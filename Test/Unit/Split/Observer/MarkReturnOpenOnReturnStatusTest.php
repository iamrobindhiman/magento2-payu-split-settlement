<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Model\ResourceModel\ReturnRequestItems;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Observer\MarkReturnOpenOnReturnStatus;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Fab\Returns\Api\Data\ReturnRequestInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #1, part (c): bridges Fab_Returns into the PayU release gate. SegmentEligibility
 * already blocks release on a per-item return_state of RETURN_OPEN, but nothing wrote it. This
 * observer is the producer: on fab_returns_status_changed it flips the matching segment items to
 * RETURN_OPEN while a return is live (submitted/under_review/approved — approved still owes a
 * customer refund, so it stays blocking) and to RETURN_REJECTED on rejection (un-stranding the
 * vendor). No-op for non-PayU orders; never breaks the returns flow.
 */
class MarkReturnOpenOnReturnStatusTest extends TestCase
{
    private ObjectManager $om;
    private $segmentLoader;
    private $itemReader;
    private $itemWriter;
    private $logger;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
        $this->segmentLoader = $this->createMock(SegmentLoader::class);
        $this->itemReader = $this->createMock(ReturnRequestItems::class);
        $this->itemWriter = $this->createMock(SplitSegmentItemResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function subject(): MarkReturnOpenOnReturnStatus
    {
        return new MarkReturnOpenOnReturnStatus(
            $this->segmentLoader,
            $this->itemReader,
            $this->itemWriter,
            $this->logger
        );
    }

    private function observerWith(?ReturnRequestInterface $request, ?string $toStatus): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnMap([
            ['return_request', null, $request],
            ['to_status', null, $toStatus],
        ]);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    private function request(int $requestId = 100, int $orderId = 42, int $sellerId = 18): ReturnRequestInterface
    {
        $request = $this->createMock(ReturnRequestInterface::class);
        $request->method('getEntityId')->willReturn($requestId);
        $request->method('getOrderId')->willReturn($orderId);
        $request->method('getSellerId')->willReturn($sellerId);
        return $request;
    }

    private function vendorSegment(int $id = 7, int $sellerId = 18): SplitSegment
    {
        /** @var SplitSegment $s */
        $s = $this->om->getObject(SplitSegment::class);
        $s->setId($id);
        $s->setData('order_id', 42);
        $s->setData('seller_id', $sellerId);
        $s->setData('segment_type', SplitSegment::TYPE_VENDOR);
        return $s;
    }

    public function testSubmittedReturnMarksMatchingItemsOpen(): void
    {
        $this->segmentLoader->method('loadByOrderId')->with(42)->willReturn([$this->vendorSegment()]);
        $this->itemReader->method('getOrderItemIds')->with(100)->willReturn([501, 502]);

        $this->itemWriter->expects($this->once())->method('markReturnState')
            ->with(7, [501, 502], SplitSegmentItem::RETURN_OPEN);

        $this->subject()->execute($this->observerWith($this->request(), 'submitted'));
    }

    public function testApprovedReturnStillMarksOpen(): void
    {
        // Approved = customer is owed a refund that has not executed yet → must stay blocking.
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment()]);
        $this->itemReader->method('getOrderItemIds')->willReturn([501]);

        $this->itemWriter->expects($this->once())->method('markReturnState')
            ->with(7, [501], SplitSegmentItem::RETURN_OPEN);

        $this->subject()->execute($this->observerWith($this->request(), 'approved'));
    }

    public function testRejectedReturnMarksItemsRejected(): void
    {
        // Rejected = nothing owed → un-strand the vendor so release can proceed.
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment()]);
        $this->itemReader->method('getOrderItemIds')->willReturn([501]);

        $this->itemWriter->expects($this->once())->method('markReturnState')
            ->with(7, [501], SplitSegmentItem::RETURN_REJECTED);

        $this->subject()->execute($this->observerWith($this->request(), 'rejected'));
    }

    public function testNonPayuOrderIsNoOp(): void
    {
        // No vendor segment for that (order, seller) → not a split order → nothing to do.
        $this->segmentLoader->method('loadByOrderId')->willReturn([]);
        $this->itemWriter->expects($this->never())->method('markReturnState');

        $this->subject()->execute($this->observerWith($this->request(), 'submitted'));
    }

    public function testSegmentForDifferentSellerIsNotTouched(): void
    {
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(9, 22)]);
        $this->itemWriter->expects($this->never())->method('markReturnState');

        $this->subject()->execute($this->observerWith($this->request(100, 42, 18), 'submitted'));
    }

    public function testMissingRequestIsNoOp(): void
    {
        $this->segmentLoader->expects($this->never())->method('loadByOrderId');
        $this->itemWriter->expects($this->never())->method('markReturnState');

        $this->subject()->execute($this->observerWith(null, 'submitted'));
    }

    public function testUnknownStatusIsNoOp(): void
    {
        $this->segmentLoader->expects($this->never())->method('loadByOrderId');
        $this->itemWriter->expects($this->never())->method('markReturnState');

        $this->subject()->execute($this->observerWith($this->request(), 'archived'));
    }

    public function testNoMatchingItemsIsNoOp(): void
    {
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment()]);
        $this->itemReader->method('getOrderItemIds')->willReturn([]);
        $this->itemWriter->expects($this->never())->method('markReturnState');

        $this->subject()->execute($this->observerWith($this->request(), 'submitted'));
    }

    public function testWriterFailureNeverBreaksReturnsFlow(): void
    {
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment()]);
        $this->itemReader->method('getOrderItemIds')->willReturn([501]);
        $this->itemWriter->method('markReturnState')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->subject()->execute($this->observerWith($this->request(), 'submitted'));
    }
}
