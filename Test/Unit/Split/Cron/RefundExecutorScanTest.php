<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\RefundExecutorScan;
use RKD\PayuSplit\Split\Model\RefundCandidate;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\RefundCandidateLoader;
use RKD\PayuSplit\Split\Service\RefundExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The second settlement scan: hand every refundable cancelled vendor segment to the
 * refund executor. Each segment runs in its own try/catch so one failing refund never
 * stalls the rest of the batch (mirrors the split-trigger scan's per-order isolation).
 */
class RefundExecutorScanTest extends TestCase
{
    private function candidate(int $segmentId, string $reason): RefundCandidate
    {
        $segment = $this->createMock(SplitSegment::class);
        $segment->method('getId')->willReturn($segmentId);
        return new RefundCandidate($segment, $reason);
    }

    public function testRefundsEveryCandidate(): void
    {
        $c1 = $this->candidate(7, SplitRefund::REASON_VENDOR_CANCEL);
        $c2 = $this->candidate(8, SplitRefund::REASON_CUSTOMER_CANCEL);

        $loader = $this->createMock(RefundCandidateLoader::class);
        $loader->method('loadCandidates')->willReturn([$c1, $c2]);

        $executor = $this->createMock(RefundExecutor::class);
        $calls = [];
        $executor->method('execute')->willReturnCallback(
            static function (SplitSegment $s, string $reason) use (&$calls): void {
                $calls[] = [$s->getId(), $reason];
            }
        );

        (new RefundExecutorScan($loader, $executor, $this->createMock(LoggerInterface::class)))->execute();

        $this->assertSame([[7, SplitRefund::REASON_VENDOR_CANCEL], [8, SplitRefund::REASON_CUSTOMER_CANCEL]], $calls);
    }

    public function testOneFailingRefundIsIsolatedAndBatchContinues(): void
    {
        $bad = $this->candidate(7, SplitRefund::REASON_VENDOR_CANCEL);
        $good = $this->candidate(8, SplitRefund::REASON_CUSTOMER_CANCEL);

        $loader = $this->createMock(RefundCandidateLoader::class);
        $loader->method('loadCandidates')->willReturn([$bad, $good]);

        $processed = [];
        $executor = $this->createMock(RefundExecutor::class);
        $executor->method('execute')->willReturnCallback(
            static function (SplitSegment $s) use (&$processed, $bad): void {
                $processed[] = $s->getId();
                if ($s === $bad->segment) {
                    throw new \RuntimeException('gateway down');
                }
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        (new RefundExecutorScan($loader, $executor, $logger))->execute();

        $this->assertContains(8, $processed, 'the second segment must still be refunded after the first errors');
    }
}
