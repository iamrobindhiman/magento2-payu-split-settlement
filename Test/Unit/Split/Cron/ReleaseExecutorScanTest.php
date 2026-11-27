<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\ReleaseExecutorScan;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\ReleaseCandidateLoader;
use RKD\PayuSplit\Split\Service\ReleaseProcessor;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The release executor scan: gated by the master release kill-switch, drives the ReleaseProcessor
 * over every release-eligible (vendor + parent) candidate with per-segment isolation. Tests pin:
 * the switch OFF makes it an explicit no-op (no loader, no processor); the switch ON releases each
 * candidate; a throwing candidate is logged and the batch continues.
 */
class ReleaseExecutorScanTest extends TestCase
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
        $loader = $this->createMock(ReleaseCandidateLoader::class);
        $processor = $this->createMock(ReleaseProcessor::class);

        $loader->expects($this->never())->method('loadCandidates');
        $processor->expects($this->never())->method('release');

        (new ReleaseExecutorScan($config, $loader, $processor, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testReleasesEachCandidateWhenEnabled(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(true);
        $segments = [$this->segment(1), $this->segment(2), $this->segment(3)];
        $loader = $this->createMock(ReleaseCandidateLoader::class);
        $loader->method('loadCandidates')->willReturn($segments);
        $processor = $this->createMock(ReleaseProcessor::class);

        $processor->expects($this->exactly(3))->method('release');

        (new ReleaseExecutorScan($config, $loader, $processor, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testOneThrowingCandidateDoesNotStallTheBatch(): void
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('isReleaseEnabled')->willReturn(true);
        $segments = [$this->segment(1), $this->segment(2)];
        $loader = $this->createMock(ReleaseCandidateLoader::class);
        $loader->method('loadCandidates')->willReturn($segments);

        $processor = $this->createMock(ReleaseProcessor::class);
        $seen = [];
        $processor->method('release')->willReturnCallback(function (SplitSegment $s) use (&$seen) {
            $seen[] = (int) $s->getId();
            if ((int) $s->getId() === 1) {
                throw new \RuntimeException('gateway hiccup');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('release executor error'));

        (new ReleaseExecutorScan($config, $loader, $processor, $logger))->execute();

        $this->assertSame([1, 2], $seen, 'Both segments must be attempted despite the first throwing.');
    }
}
