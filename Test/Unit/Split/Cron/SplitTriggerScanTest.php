<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\SplitTriggerScan;
use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\MoneyEventDetector;
use RKD\PayuSplit\Split\Service\PendingSplitLoader;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use RKD\PayuSplit\Split\Service\SplitRequester;
use RKD\PayuSplit\Split\Service\SplitStateMachine;
use RKD\PayuSplit\Split\Service\SplitTriggerResolver;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The split-trigger cron. It scans unfinished headers, resolves each one's trigger
 * (against a REAL SplitTriggerResolver so the wiring is exercised end-to-end), and
 * either stamps the trigger + hands off to the requester, or raises a compliance
 * alert. A failure on one header is isolated (header → FAILED) and the scan moves
 * on — one bad order never stalls the batch.
 */
class SplitTriggerScanTest extends TestCase
{
    /** @var PendingSplitLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $headerLoader;
    /** @var SegmentLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $segmentLoader;
    /** @var SellerOrderStatus&\PHPUnit\Framework\MockObject\MockObject */
    private $sellerOrderStatus;
    /** @var MoneyEventDetector&\PHPUnit\Framework\MockObject\MockObject */
    private $moneyEventDetector;
    /** @var SplitRequester&\PHPUnit\Framework\MockObject\MockObject */
    private $requester;
    /** @var SplitStateMachine&\PHPUnit\Framework\MockObject\MockObject */
    private $stateMachine;
    /** @var SplitConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->headerLoader = $this->createMock(PendingSplitLoader::class);
        $this->segmentLoader = $this->createMock(SegmentLoader::class);
        $this->sellerOrderStatus = $this->createMock(SellerOrderStatus::class);
        $this->moneyEventDetector = $this->createMock(MoneyEventDetector::class);
        $this->requester = $this->createMock(SplitRequester::class);
        $this->stateMachine = $this->createMock(SplitStateMachine::class);
        $this->config = $this->createMock(SplitConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getCeilingDays')->willReturn(25);
        $this->config->method('getAlertDays')->willReturn(23);
    }

    private function scan(string $now): SplitTriggerScan
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn($now);

        return new SplitTriggerScan(
            $this->headerLoader,
            $this->segmentLoader,
            $this->sellerOrderStatus,
            new SplitTriggerResolver(),
            $this->moneyEventDetector,
            $this->requester,
            $this->stateMachine,
            $this->config,
            $dateTime,
            $this->logger
        );
    }

    /**
     * @param int $orderId
     * @param string|null $paidAt
     * @return SplitOrder
     */
    private function header(int $orderId, ?string $paidAt): SplitOrder
    {
        $header = $this->createMock(SplitOrder::class);
        $header->method('getOrderId')->willReturn($orderId);
        $header->method('getData')->willReturnMap([['paid_at', null, $paidAt]]);
        $header->method('getSplitState')->willReturn(SplitOrder::STATE_PENDING);
        return $header;
    }

    private function vendorSegment(int $sellerId): SplitSegment
    {
        $segment = $this->createMock(SplitSegment::class);
        $segment->method('getSegmentType')->willReturn(SplitSegment::TYPE_VENDOR);
        $segment->method('getSellerId')->willReturn($sellerId);
        return $segment;
    }

    public function testTriggerReadyOrderIsStampedAndHandedToRequester(): void
    {
        $header = $this->header(45, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$header]);
        $this->segmentLoader->method('loadByOrderId')->with(45)->willReturn([$this->vendorSegment(18)]);
        $this->sellerOrderStatus->method('getTerminalFlags')->with(45)->willReturn([18 => true]);

        $header->expects($this->once())->method('setData')->with('split_trigger', SplitTriggerResolver::TRIGGER_ALL_TERMINAL);
        $this->requester->expects($this->once())->method('request')->with($header);
        $this->logger->expects($this->never())->method('warning');

        $this->scan('2026-06-02 00:00:00')->execute();
    }

    public function testAgeingUnsplitOrderRaisesComplianceAlertNotSplit(): void
    {
        $header = $this->header(46, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$header]);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(18)]);
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturn([18 => false]); // not shipped

        $this->requester->expects($this->never())->method('request');
        $header->expects($this->never())->method('setData');
        $this->logger->expects($this->once())->method('warning');

        // 24 days after paid_at: past alert (23), below ceiling (25).
        $this->scan('2026-06-25 00:00:00')->execute();
    }

    public function testCeilingOrderIsSplit(): void
    {
        $header = $this->header(47, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$header]);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(18)]);
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturn([18 => false]);

        $header->expects($this->once())->method('setData')->with('split_trigger', SplitTriggerResolver::TRIGGER_CEILING);
        $this->requester->expects($this->once())->method('request')->with($header);

        // 25 days after paid_at: ceiling fires.
        $this->scan('2026-06-26 00:00:00')->execute();
    }

    public function testYoungUnshippedOrderIsLeftAlone(): void
    {
        $header = $this->header(48, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$header]);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(18)]);
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturn([18 => false]);

        $this->requester->expects($this->never())->method('request');
        $this->logger->expects($this->never())->method('warning');
        $header->expects($this->never())->method('setData');

        $this->scan('2026-06-05 00:00:00')->execute(); // 4 days
    }

    public function testMoneyEventBringsSplitForwardOnAnUnshippedOrderWithACancelledVendor(): void
    {
        // A young order whose vendors have NOT all shipped would normally wait — but a
        // cancelled vendor portion is a money event: the split must fire now so the
        // customer can be refunded promptly (Fact 4 needs the split to exist first).
        $header = $this->header(49, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$header]);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(18), $this->vendorSegment(20)]);
        // Neither vendor shipped (so ALL_TERMINAL is not met), but one portion is cancelled.
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturn([18 => false, 20 => false]);
        $this->moneyEventDetector->method('isMoneyEvent')->with(49)->willReturn(true);

        $header->expects($this->once())->method('setData')->with('split_trigger', SplitTriggerResolver::TRIGGER_MONEY_EVENT);
        $this->requester->expects($this->once())->method('request')->with($header);

        $this->scan('2026-06-03 00:00:00')->execute(); // 2 days — young, no ceiling/alert
    }

    public function testInfraErrorOnOneHeaderIsLoggedAndBatchContinuesWithoutFailing(): void
    {
        $bad = $this->header(50, '2026-06-01 00:00:00');
        $good = $this->header(51, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$bad, $good]);

        $this->segmentLoader->method('loadByOrderId')->willReturnMap([
            [50, [$this->vendorSegment(18)]],
            [51, [$this->vendorSegment(20)]],
        ]);
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturnMap([
            [50, [18 => true]],
            [51, [20 => true]],
        ]);

        // A generic/infra error (e.g. a DB deadlock) on the first header must NOT
        // terminalise it — it may be transient — but the batch must continue and the
        // second header must still be processed.
        $processed = [];
        $this->requester->method('request')->willReturnCallback(
            function (SplitOrder $h) use ($bad, &$processed) {
                $processed[] = $h->getOrderId();
                if ($h === $bad) {
                    throw new \RuntimeException('db deadlock');
                }
            }
        );

        $this->stateMachine->expects($this->never())->method('markFailed');
        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->scan('2026-06-02 00:00:00')->execute();

        $this->assertContains(51, $processed, 'the second header must still be processed after the first errors');
    }

    public function testSnapshotCorruptionMarksHeaderFailed(): void
    {
        $bad = $this->header(52, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$bad]);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(18)]);
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturn([18 => true]);

        // A snapshot that no longer reconciles surfaces as SplitCalculationException out
        // of the requester — that IS terminal (it will not self-heal) → FAILED.
        $this->requester->method('request')
            ->willThrowException(new SplitCalculationException('snapshot does not reconcile'));

        $this->stateMachine->expects($this->once())
            ->method('markFailed')
            ->with($bad, $this->stringContains('reconcile'));

        $this->scan('2026-06-02 00:00:00')->execute();
    }

    public function testAgedTriggerReadyButUnsplittableOrderStillRaisesAlarm(): void
    {
        // The F-2 blind spot: an aged order whose trigger fired (ALL_TERMINAL) but whose
        // split did not complete (e.g. no mihpayid → request() leaves it not-DONE) must
        // STILL alarm before the ceiling — a non-null trigger no longer suppresses it.
        $header = $this->header(60, '2026-06-01 00:00:00');
        $this->headerLoader->method('loadUnfinished')->willReturn([$header]);
        $this->segmentLoader->method('loadByOrderId')->willReturn([$this->vendorSegment(18)]);
        $this->sellerOrderStatus->method('getTerminalFlags')->willReturn([18 => true]);

        $header->expects($this->once())->method('setData')->with('split_trigger', SplitTriggerResolver::TRIGGER_ALL_TERMINAL);
        $this->requester->expects($this->once())->method('request')->with($header); // no-op: stays PENDING
        $this->logger->expects($this->once())->method('warning');

        $this->scan('2026-06-25 00:00:00')->execute(); // 24 days — aged
    }
}
