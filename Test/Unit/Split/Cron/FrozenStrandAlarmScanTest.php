<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\FrozenStrandAlarmScan;
use RKD\PayuSplit\Split\Model\ResourceModel\StaleFrozenSegments;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Gate item #11: a PayU-split segment carrying a FROZEN hold past the alert threshold — a freeze
 * that has sat unreconciled (release readback mismatch, O-13 gap, or the credit-memo/recon freeze)
 * — must be ALARMED, never a silent strand (the RBI "never strand unalarmed" rule; a frozen segment
 * blocks the order's parent commission via INV-14). This daily scan reads the stale frozen segments
 * and CRITICAL-logs each; an empty result is a silent no-op. Logging-only — moves no money.
 */
class FrozenStrandAlarmScanTest extends TestCase
{
    private $reader;
    private $config;
    private $dateTime;
    private $logger;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(StaleFrozenSegments::class);
        $this->config = $this->createMock(SplitConfig::class);
        $this->config->method('getAlertDays')->willReturn(7);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-20 00:00:00');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function subject(): FrozenStrandAlarmScan
    {
        return new FrozenStrandAlarmScan($this->reader, $this->config, $this->dateTime, $this->logger);
    }

    public function testAlarmsEachStaleFrozenSegment(): void
    {
        // cutoff = now (2026-06-20) - alertDays (7) = 2026-06-13.
        $this->reader->expects($this->once())->method('getStaleFrozenSegments')
            ->with('2026-06-13 00:00:00')
            ->willReturn([
                ['segment_id' => 7, 'order_id' => 42, 'seller_id' => 18, 'hold_type' => 'FROZEN',
                    'frozen_at' => '2026-06-01 00:00:00', 'note' => 'credit memo (F5)'],
                ['segment_id' => 9, 'order_id' => 51, 'seller_id' => 0, 'hold_type' => 'FROZEN',
                    'frozen_at' => '2026-06-02 00:00:00', 'note' => null],
            ]);

        $this->logger->expects($this->exactly(2))->method('critical');

        $this->subject()->execute();
    }

    public function testNoStaleFrozenSegmentsIsSilentNoOp(): void
    {
        $this->reader->method('getStaleFrozenSegments')->willReturn([]);
        $this->logger->expects($this->never())->method('critical');

        $this->subject()->execute();
    }

    public function testReadFailureIsSwallowedAndLoggedNotThrown(): void
    {
        // A logging-only scan must never let a bad read escalate — log an error and move on.
        $this->reader->method('getStaleFrozenSegments')
            ->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->never())->method('critical');
        $this->logger->expects($this->once())->method('error');

        $this->subject()->execute();
    }
}
