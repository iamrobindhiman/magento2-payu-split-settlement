<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\ReleaseReconScan;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\ReleaseReconciler;
use RKD\PayuSplit\Split\Service\ReleaseRequestedLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The release-recon scan: gated by the master release kill-switch, drives the reconciler over
 * every RELEASE_REQUESTED segment with per-segment isolation. Tests pin: the switch OFF makes it
 * an explicit no-op (no loader, no reconciler); the switch ON reconciles each candidate; a
 * throwing candidate is logged and the batch continues.
 */
class ReleaseReconScanTest extends TestCase
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
        $segment->setId($id); // getId() reads the model id field, not segment_id
        return $segment;
    }

    public function testKillSwitchOffIsNoOp(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(false);
        $loader = $this->createMock(ReleaseRequestedLoader::class);
        $reconciler = $this->createMock(ReleaseReconciler::class);

        $loader->expects($this->never())->method('loadRequested');
        $reconciler->expects($this->never())->method('reconcile');

        (new ReleaseReconScan($config, $loader, $reconciler, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testReconcilesEachCandidateWhenEnabled(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(true);
        $segments = [$this->segment(1), $this->segment(2)];
        $loader = $this->createMock(ReleaseRequestedLoader::class);
        $loader->method('loadRequested')->willReturn($segments);
        $reconciler = $this->createMock(ReleaseReconciler::class);

        $reconciler->expects($this->exactly(2))->method('reconcile');

        (new ReleaseReconScan($config, $loader, $reconciler, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testOneThrowingCandidateDoesNotStallTheBatch(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(true);
        $segments = [$this->segment(1), $this->segment(2)];
        $loader = $this->createMock(ReleaseRequestedLoader::class);
        $loader->method('loadRequested')->willReturn($segments);

        $reconciler = $this->createMock(ReleaseReconciler::class);
        $seen = [];
        $reconciler->method('reconcile')->willReturnCallback(function (SplitSegment $s) use (&$seen) {
            $seen[] = (int) $s->getId();
            if ((int) $s->getId() === 1) {
                throw new \RuntimeException('gateway hiccup');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('release recon error'));

        (new ReleaseReconScan($config, $loader, $reconciler, $logger))->execute();

        $this->assertSame([1, 2], $seen, 'Both segments must be attempted despite the first throwing.');
    }
}
