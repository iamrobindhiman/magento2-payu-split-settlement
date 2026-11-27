<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Observer\MirrorReleasedSegment;
use RKD\PayuSplit\Split\Service\WebkulSettlementSync;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The release-event observer: pulls the segment off rkd_payu_segment_released and hands it to the
 * sync. Tests pin: a SplitSegment payload is synced; a non-segment payload is ignored; a sync
 * failure is logged and NOT rethrown (so it can never disrupt the recon that dispatched the event).
 */
class MirrorReleasedSegmentTest extends TestCase
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

    public function testSyncsTheReleasedSegment(): void
    {
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setId(7);
        $sync = $this->createMock(WebkulSettlementSync::class);
        $sync->expects($this->once())->method('sync')->with($segment);

        (new MirrorReleasedSegment($sync, $this->createMock(LoggerInterface::class)))
            ->execute($this->observerWith($segment));
    }

    public function testNonSegmentPayloadIsIgnored(): void
    {
        $sync = $this->createMock(WebkulSettlementSync::class);
        $sync->expects($this->never())->method('sync');

        (new MirrorReleasedSegment($sync, $this->createMock(LoggerInterface::class)))
            ->execute($this->observerWith(null));
    }

    public function testSyncFailureIsLoggedNotRethrown(): void
    {
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setId(7);
        $sync = $this->createMock(WebkulSettlementSync::class);
        $sync->method('sync')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('mirror observer error'));

        // Must not throw.
        (new MirrorReleasedSegment($sync, $logger))->execute($this->observerWith($segment));
    }
}
