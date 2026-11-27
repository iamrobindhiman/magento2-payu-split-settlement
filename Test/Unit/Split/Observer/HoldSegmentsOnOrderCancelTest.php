<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Observer\HoldSegmentsOnOrderCancel;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #1, part (b): on a FULL order cancel (order_cancel_after, state=canceled) every
 * non-terminal vendor segment of the order is held CANCELED synchronously, so the release gate
 * (which excludes held segments) cannot race a cron tick into releasing escrow that is owed back
 * to the customer. Guarded against Webkul's partial-cancel firing of the same event (the partial
 * case is handled per-seller by HoldSegmentOnSellerCancel), and never breaks the cancel flow.
 */
class HoldSegmentsOnOrderCancelTest extends TestCase
{
    private ObjectManager $om;
    private $segmentLoader;
    private $holdWriter;
    private $logger;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
        $this->segmentLoader = $this->createMock(SegmentLoader::class);
        $this->holdWriter = $this->createMock(SegmentHoldWriter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function subject(): HoldSegmentsOnOrderCancel
    {
        return new HoldSegmentsOnOrderCancel($this->segmentLoader, $this->holdWriter, $this->logger);
    }

    private function observerWith(?Order $order): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('order')->willReturn($order);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    private function order(string $state): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnMap([
            ['state', null, $state],
            ['entity_id', null, 42],
        ]);
        return $order;
    }

    private function segment(int $id, string $type, int $sellerId, string $state): SplitSegment
    {
        /** @var SplitSegment $s */
        $s = $this->om->getObject(SplitSegment::class);
        $s->setId($id);
        $s->setData('order_id', 42);
        $s->setData('seller_id', $sellerId);
        $s->setData('segment_type', $type);
        $s->setState($state);
        return $s;
    }

    public function testHoldsEveryNonTerminalVendorSegmentOnFullCancel(): void
    {
        $vendorA = $this->segment(7, SplitSegment::TYPE_VENDOR, 18, SplitSegment::STATE_ELIGIBLE);
        $vendorB = $this->segment(9, SplitSegment::TYPE_VENDOR, 22, SplitSegment::STATE_PENDING);
        $this->segmentLoader->method('loadByOrderId')->with(42)->willReturn([$vendorA, $vendorB]);

        $held = [];
        $this->holdWriter->method('place')->willReturnCallback(
            function (int $id, string $type) use (&$held) {
                $held[] = [$id, $type];
            }
        );

        $this->subject()->execute($this->observerWith($this->order(Order::STATE_CANCELED)));

        $this->assertContains([7, SplitHold::TYPE_CANCELED], $held);
        $this->assertContains([9, SplitHold::TYPE_CANCELED], $held);
    }

    public function testSkipsParentCommissionSegment(): void
    {
        $vendor = $this->segment(7, SplitSegment::TYPE_VENDOR, 18, SplitSegment::STATE_ELIGIBLE);
        $parent = $this->segment(8, SplitSegment::TYPE_COMMISSION, 0, SplitSegment::STATE_PENDING);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$vendor, $parent]);

        $this->holdWriter->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_CANCELED, $this->anything());

        $this->subject()->execute($this->observerWith($this->order(Order::STATE_CANCELED)));
    }

    public function testSkipsTerminalSegment(): void
    {
        $released = $this->segment(7, SplitSegment::TYPE_VENDOR, 18, SplitSegment::STATE_RELEASED);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$released]);

        $this->holdWriter->expects($this->never())->method('place');

        $this->subject()->execute($this->observerWith($this->order(Order::STATE_CANCELED)));
    }

    public function testIgnoresPartialCancel(): void
    {
        // Webkul fires order_cancel_after on partial seller cancels too. On a partial (state !=
        // canceled) the other sellers' segments are NOT cancelled, so this observer must stay out
        // — the per-seller HoldSegmentOnSellerCancel handles the cancelling seller's segment.
        $this->segmentLoader->expects($this->never())->method('loadByOrderId');
        $this->holdWriter->expects($this->never())->method('place');

        $this->subject()->execute($this->observerWith($this->order(Order::STATE_PROCESSING)));
    }

    public function testMissingOrderIsNoOp(): void
    {
        $this->segmentLoader->expects($this->never())->method('loadByOrderId');
        $this->holdWriter->expects($this->never())->method('place');

        $this->subject()->execute($this->observerWith(null));
    }

    public function testWriterFailureNeverBreaksCancelFlow(): void
    {
        $vendor = $this->segment(7, SplitSegment::TYPE_VENDOR, 18, SplitSegment::STATE_ELIGIBLE);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$vendor]);
        $this->holdWriter->method('place')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->atLeastOnce())->method('error');

        // Must not throw.
        $this->subject()->execute($this->observerWith($this->order(Order::STATE_CANCELED)));
    }
}
