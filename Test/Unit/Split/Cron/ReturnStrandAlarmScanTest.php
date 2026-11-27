<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\ReturnStrandAlarmScan;
use RKD\PayuSplit\Split\Model\ResourceModel\StaleReturnSegments;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * #9: a PayU-split vendor segment stuck RETURN_OPEN past the alert threshold (an approved return
 * whose refund never executed — returns-refund is deferred to Fab_Returns v2) must be ALARMED,
 * never a silent strand (the RBI "never strand unalarmed" rule). This scan reads the stale
 * open-return segments and CRITICAL-logs each; an empty result is a silent no-op.
 */
class ReturnStrandAlarmScanTest extends TestCase
{
    private $reader;
    private $config;
    private $dateTime;
    private $logger;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(StaleReturnSegments::class);
        $this->config = $this->createMock(SplitConfig::class);
        $this->config->method('getAlertDays')->willReturn(7);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-20 00:00:00');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function subject(): ReturnStrandAlarmScan
    {
        return new ReturnStrandAlarmScan($this->reader, $this->config, $this->dateTime, $this->logger);
    }

    public function testAlarmsEachStaleOpenReturnSegment(): void
    {
        // cutoff = now (2026-06-20) - alertDays (7) = 2026-06-13.
        $this->reader->expects($this->once())->method('getStaleOpenReturnSegments')
            ->with('2026-06-13 00:00:00')
            ->willReturn([
                ['segment_id' => 7, 'order_id' => 42, 'seller_id' => 18, 'opened_at' => '2026-06-01 00:00:00'],
                ['segment_id' => 9, 'order_id' => 51, 'seller_id' => 22, 'opened_at' => '2026-06-02 00:00:00'],
            ]);

        $this->logger->expects($this->exactly(2))->method('critical');

        $this->subject()->execute();
    }

    public function testNoStaleSegmentsIsSilentNoOp(): void
    {
        $this->reader->method('getStaleOpenReturnSegments')->willReturn([]);
        $this->logger->expects($this->never())->method('critical');

        $this->subject()->execute();
    }
}
