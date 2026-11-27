<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Observer\HoldSegmentOnSellerCancel;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #1, part (b): on a Webkul seller cancel (mp_order_cancel_after, partial OR full)
 * the cancelling seller's vendor segment is held CANCELED synchronously. A PayU vendor segment is
 * one row per (order, seller), so a partial item cancel still holds the whole segment — safe
 * (delays release, never loses money; the refund executor then refunds the cancelled portion and
 * the live items un-strand once the segment terminalises). Never breaks the cancel flow.
 */
class HoldSegmentOnSellerCancelTest extends TestCase
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

    private function subject(): HoldSegmentOnSellerCancel
    {
        return new HoldSegmentOnSellerCancel($this->segmentLoader, $this->holdWriter, $this->logger);
    }

    private function observerWith(?Order $order, int $sellerId): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnMap([
            ['order', null, $order],
            ['seller_id', null, $sellerId],
        ]);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnMap([['entity_id', null, 42]]);
        return $order;
    }

    private function segment(int $id, int $sellerId, string $state): SplitSegment
    {
        /** @var SplitSegment $s */
        $s = $this->om->getObject(SplitSegment::class);
        $s->setId($id);
        $s->setData('order_id', 42);
        $s->setData('seller_id', $sellerId);
        $s->setData('segment_type', SplitSegment::TYPE_VENDOR);
        $s->setState($state);
        return $s;
    }

    public function testHoldsOnlyTheCancellingSellersSegment(): void
    {
        $mine = $this->segment(7, 18, SplitSegment::STATE_ELIGIBLE);
        $other = $this->segment(9, 22, SplitSegment::STATE_ELIGIBLE);
        $this->segmentLoader->method('loadByOrderId')->with(42)->willReturn([$mine, $other]);

        $this->holdWriter->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_CANCELED, $this->anything());

        $this->subject()->execute($this->observerWith($this->order(), 18));
    }

    public function testSkipsTerminalSegment(): void
    {
        $mine = $this->segment(7, 18, SplitSegment::STATE_REFUNDED);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$mine]);

        $this->holdWriter->expects($this->never())->method('place');

        $this->subject()->execute($this->observerWith($this->order(), 18));
    }

    public function testMissingSellerIdIsNoOp(): void
    {
        $this->segmentLoader->expects($this->never())->method('loadByOrderId');
        $this->holdWriter->expects($this->never())->method('place');

        $this->subject()->execute($this->observerWith($this->order(), 0));
    }

    public function testMissingOrderIsNoOp(): void
    {
        $this->segmentLoader->expects($this->never())->method('loadByOrderId');
        $this->holdWriter->expects($this->never())->method('place');

        $this->subject()->execute($this->observerWith(null, 18));
    }

    public function testWriterFailureNeverBreaksCancelFlow(): void
    {
        $mine = $this->segment(7, 18, SplitSegment::STATE_ELIGIBLE);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$mine]);
        $this->holdWriter->method('place')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->subject()->execute($this->observerWith($this->order(), 18));
    }
}
