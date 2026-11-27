<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\WebkulSyncReconScan;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\UnsyncedReleasedLoader;
use RKD\PayuSplit\Split\Service\WebkulSettlementSync;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Webkul-mirror reconcile scan (self-heal backstop): gated by the release kill-switch, re-drives
 * the sync over every RELEASED-but-unsynced segment with per-segment isolation. Tests pin: switch
 * OFF makes it a no-op; switch ON syncs each; a throwing segment is logged and the batch continues.
 */
class WebkulSyncReconScanTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    private function segment(int $id): SplitSegment
    {
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setData(['segment_id' => $id, 'order_id' => 40 + $id]);
        $segment->setId($id);
        return $segment;
    }

    public function testKillSwitchOffIsNoOp(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(false);
        $loader = $this->createMock(UnsyncedReleasedLoader::class);
        $sync = $this->createMock(WebkulSettlementSync::class);

        $loader->expects($this->never())->method('loadUnsynced');
        $sync->expects($this->never())->method('sync');

        (new WebkulSyncReconScan($config, $loader, $sync, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testSyncsEachUnsyncedSegmentWhenEnabled(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(true);
        $loader = $this->createMock(UnsyncedReleasedLoader::class);
        $loader->method('loadUnsynced')->willReturn([$this->segment(1), $this->segment(2)]);
        $sync = $this->createMock(WebkulSettlementSync::class);

        $sync->expects($this->exactly(2))->method('sync');

        (new WebkulSyncReconScan($config, $loader, $sync, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testOneThrowingSegmentDoesNotStallTheBatch(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(true);
        $loader = $this->createMock(UnsyncedReleasedLoader::class);
        $loader->method('loadUnsynced')->willReturn([$this->segment(1), $this->segment(2)]);

        $sync = $this->createMock(WebkulSettlementSync::class);
        $seen = [];
        $sync->method('sync')->willReturnCallback(function (SplitSegment $s) use (&$seen) {
            $seen[] = (int) $s->getId();
            if ((int) $s->getId() === 1) {
                throw new \RuntimeException('webkul down');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Webkul mirror reconcile error'));

        (new WebkulSyncReconScan($config, $loader, $sync, $logger))->execute();

        $this->assertSame([1, 2], $seen);
    }
}
