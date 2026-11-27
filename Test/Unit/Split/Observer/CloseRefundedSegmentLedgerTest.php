<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Observer\CloseRefundedSegmentLedger;
use RKD\PayuSplit\Split\Service\WebkulRefundCloser;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refund-event observer (gate blocker #4): pulls the segment off rkd_payu_segment_refunded and
 * hands it to the closer. Tests pin: a SplitSegment payload is closed; a non-segment payload is
 * ignored; a close failure is logged and NOT rethrown (so it can never disrupt the refund executor
 * that dispatched the event — the reconcile scan retries).
 */
class CloseRefundedSegmentLedgerTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    private function observerWith(mixed $segment): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('segment')->willReturn($segment);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    public function testClosesTheRefundedSegment(): void
    {
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setId(7);
        $closer = $this->createMock(WebkulRefundCloser::class);
        $closer->expects($this->once())->method('closeRefunded')->with($segment);

        (new CloseRefundedSegmentLedger($closer, $this->createMock(LoggerInterface::class)))
            ->execute($this->observerWith($segment));
    }

    public function testNonSegmentPayloadIsIgnored(): void
    {
        $closer = $this->createMock(WebkulRefundCloser::class);
        $closer->expects($this->never())->method('closeRefunded');

        (new CloseRefundedSegmentLedger($closer, $this->createMock(LoggerInterface::class)))
            ->execute($this->observerWith(null));
    }

    public function testCloseFailureIsLoggedNotRethrown(): void
    {
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setId(7);
        $closer = $this->createMock(WebkulRefundCloser::class);
        $closer->method('closeRefunded')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('close observer error'));

        // Must not throw.
        (new CloseRefundedSegmentLedger($closer, $logger))->execute($this->observerWith($segment));
    }
}
