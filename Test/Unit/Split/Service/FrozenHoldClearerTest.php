<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Service\CreditMemoRefundReader;
use RKD\PayuSplit\Split\Service\FrozenHoldClearer;
use RKD\PayuSplit\Split\Service\RefundLedger;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentItemLoader;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The clear-hold break-glass (#11): remove a FROZEN hold so the segment re-enters the normal
 * release/refund gates. Money-critical only in that it must NEVER clear a freeze whose out-of-band
 * condition still holds — so it re-checks the credit-memo signal (the auto-checkable freeze cause)
 * and REFUSES if present. It moves no money itself: clearing merely re-exposes the segment to the
 * downstream gates, which re-check every condition and re-freeze if it persists.
 */
class FrozenHoldClearerTest extends TestCase
{
    private function segment(int $orderId = 42): SplitSegment
    {
        $segment = $this->createMock(SplitSegment::class);
        $segment->method('getOrderId')->willReturn($orderId);
        return $segment;
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: FrozenHoldClearer, 1: array<string, object>}
     */
    private function make(array $overrides = []): array
    {
        $deps = [
            'segmentLoader' => $this->createMock(SegmentLoader::class),
            'holdLoader' => $this->createMock(SegmentHoldLoader::class),
            'holdWriter' => $this->createMock(SegmentHoldWriter::class),
            'creditMemoReader' => $this->createMock(CreditMemoRefundReader::class),
            'itemLoader' => $this->createMock(SegmentItemLoader::class),
            // Default: no refund-ledger entanglement (activeLegs [] and failedLegCount 0 are the
            // auto-mock returns), so the credit-memo/clear paths are reached by the other tests.
            'refundLedger' => $this->createMock(RefundLedger::class),
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }
        return [new FrozenHoldClearer(
            $deps['segmentLoader'],
            $deps['holdLoader'],
            $deps['holdWriter'],
            $deps['creditMemoReader'],
            $deps['itemLoader'],
            $deps['refundLedger']
        ), $deps];
    }

    public function testRefusesWhenSegmentNotFound(): void
    {
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn(null);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->never())->method('remove');

        [$clearer] = $this->make(['segmentLoader' => $segmentLoader, 'holdWriter' => $holdWriter]);

        $this->expectException(LocalizedException::class);
        $clearer->clear(7);
    }

    public function testRefusesWhenNoFrozenHold(): void
    {
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn($this->segment());
        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        // Only a CANCELED hold (not FROZEN) — nothing to clear.
        $holdLoader->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_CANCELED]);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->never())->method('remove');

        [$clearer] = $this->make([
            'segmentLoader' => $segmentLoader, 'holdLoader' => $holdLoader, 'holdWriter' => $holdWriter,
        ]);

        $this->expectException(LocalizedException::class);
        $clearer->clear(7);
    }

    public function testRefusesWhenSegmentHasFailedRefundLeg(): void
    {
        // F6 HOLE: the refund reconciler FROZEN-holds a segment whose refunded_paise was optimistically
        // bumped, then FAILED at PayU (leaving a FAILED leg). Clearing that hold would re-fire a var8
        // on the corrupted balance → silent under-refund. The clearer must REFUSE — reconcile first.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn($this->segment());
        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        $holdLoader->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_FROZEN]);
        $refundLedger = $this->createMock(RefundLedger::class);
        $refundLedger->method('activeLegs')->with(7)->willReturn([]);
        $refundLedger->method('failedLegCount')->with(7)->willReturn(1);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->never())->method('remove');

        [$clearer] = $this->make([
            'segmentLoader' => $segmentLoader, 'holdLoader' => $holdLoader,
            'refundLedger' => $refundLedger, 'holdWriter' => $holdWriter,
        ]);

        $this->expectException(LocalizedException::class);
        $clearer->clear(7);
    }

    public function testRefusesWhenSegmentHasActiveRefundLeg(): void
    {
        // A held/in-flight refund leg is likewise unresolved refund-ledger activity — refuse.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn($this->segment());
        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        $holdLoader->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_FROZEN]);
        $refundLedger = $this->createMock(RefundLedger::class);
        $refundLedger->method('activeLegs')->with(7)->willReturn([$this->createMock(SplitRefund::class)]);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->never())->method('remove');

        [$clearer] = $this->make([
            'segmentLoader' => $segmentLoader, 'holdLoader' => $holdLoader,
            'refundLedger' => $refundLedger, 'holdWriter' => $holdWriter,
        ]);

        $this->expectException(LocalizedException::class);
        $clearer->clear(7);
    }

    public function testRefusesWhenPerItemCreditMemoStillPresent(): void
    {
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn($this->segment());
        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        $holdLoader->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_FROZEN]);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->with(7)->willReturn([52]);
        $creditMemoReader = $this->createMock(CreditMemoRefundReader::class);
        $creditMemoReader->method('refundedPaiseForItems')->with([52])->willReturn(6000);
        $creditMemoReader->method('orderLevelRefundPaise')->with(42)->willReturn(0);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->never())->method('remove');

        [$clearer] = $this->make([
            'segmentLoader' => $segmentLoader, 'holdLoader' => $holdLoader, 'itemLoader' => $itemLoader,
            'creditMemoReader' => $creditMemoReader, 'holdWriter' => $holdWriter,
        ]);

        $this->expectException(LocalizedException::class);
        $clearer->clear(7);
    }

    public function testRefusesWhenOrderLevelCreditMemoStillPresent(): void
    {
        // Per-item clean but an order-level adjustment/shipping memo still stands → still refuse.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn($this->segment());
        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        $holdLoader->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_FROZEN]);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->with(7)->willReturn([52]);
        $creditMemoReader = $this->createMock(CreditMemoRefundReader::class);
        $creditMemoReader->method('refundedPaiseForItems')->with([52])->willReturn(0);
        $creditMemoReader->method('orderLevelRefundPaise')->with(42)->willReturn(500);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->never())->method('remove');

        [$clearer] = $this->make([
            'segmentLoader' => $segmentLoader, 'holdLoader' => $holdLoader, 'itemLoader' => $itemLoader,
            'creditMemoReader' => $creditMemoReader, 'holdWriter' => $holdWriter,
        ]);

        $this->expectException(LocalizedException::class);
        $clearer->clear(7);
    }

    public function testClearsWhenReconciledAndReturnsRemovedCount(): void
    {
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadById')->with(7)->willReturn($this->segment());
        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        $holdLoader->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_FROZEN]);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->with(7)->willReturn([52]);
        $creditMemoReader = $this->createMock(CreditMemoRefundReader::class);
        $creditMemoReader->method('refundedPaiseForItems')->with([52])->willReturn(0);
        $creditMemoReader->method('orderLevelRefundPaise')->with(42)->willReturn(0);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->once())->method('remove')
            ->with(7, SplitHold::TYPE_FROZEN)->willReturn(1);

        [$clearer] = $this->make([
            'segmentLoader' => $segmentLoader, 'holdLoader' => $holdLoader, 'itemLoader' => $itemLoader,
            'creditMemoReader' => $creditMemoReader, 'holdWriter' => $holdWriter,
        ]);

        $this->assertSame(1, $clearer->clear(7));
    }
}
