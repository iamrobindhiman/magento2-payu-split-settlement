<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\RefundLegStrandAlarmScan;
use RKD\PayuSplit\Split\Model\ResourceModel\StaleRefundLegs;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Gate item #13: a refund leg the reconciler never resolves — a HELD freeze marker, or a
 * PENDING/QUEUED leg with no PayU request id (FULL_UNWIND obligation / absent-parent commission) —
 * blocks its segment's release with no recurring alarm. Past the alert threshold it must be ALARMED,
 * never a silent strand (the RBI "never strand unalarmed" rule). This daily scan reads the stale
 * unhandled legs and CRITICAL-logs each; an empty result is a silent no-op. Logging-only.
 */
class RefundLegStrandAlarmScanTest extends TestCase
{
    private $reader;
    private $config;
    private $dateTime;
    private $logger;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(StaleRefundLegs::class);
        $this->config = $this->createMock(SplitConfig::class);
        $this->config->method('getAlertDays')->willReturn(7);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-20 00:00:00');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function subject(): RefundLegStrandAlarmScan
    {
        return new RefundLegStrandAlarmScan($this->reader, $this->config, $this->dateTime, $this->logger);
    }

    public function testAlarmsEachStaleUnhandledLeg(): void
    {
        // cutoff = now (2026-06-20) - alertDays (7) = 2026-06-13.
        $this->reader->expects($this->once())->method('getStaleUnhandledLegs')
            ->with('2026-06-13 00:00:00')
            ->willReturn([
                ['refund_id' => 3, 'order_id' => 42, 'segment_id' => 7, 'seller_id' => 18,
                    'state' => 'HELD', 'reason' => 'VENDOR_CANCEL', 'created_at' => '2026-06-01 00:00:00'],
                ['refund_id' => 5, 'order_id' => 42, 'segment_id' => 9, 'seller_id' => 0,
                    'state' => 'PENDING', 'reason' => 'FULL_UNWIND', 'created_at' => '2026-06-02 00:00:00'],
            ]);

        $this->logger->expects($this->exactly(2))->method('critical');

        $this->subject()->execute();
    }

    public function testNoStaleLegsIsSilentNoOp(): void
    {
        $this->reader->method('getStaleUnhandledLegs')->willReturn([]);
        $this->logger->expects($this->never())->method('critical');

        $this->subject()->execute();
    }

    public function testReadFailureIsSwallowedAndLoggedNotThrown(): void
    {
        $this->reader->method('getStaleUnhandledLegs')
            ->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->never())->method('critical');
        $this->logger->expects($this->once())->method('error');

        $this->subject()->execute();
    }
}
