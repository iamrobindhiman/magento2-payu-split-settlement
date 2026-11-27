<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\ItemGate;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use PHPUnit\Framework\TestCase;

/**
 * The per-vendor release gate. A segment is releasable only when every item is
 * delivered, past its own window (strictly), free of an open return, the segment
 * is unheld, in a pre-release state, and has no pending refund. Each blocker is
 * asserted independently so a regression points at the exact failing condition.
 */
class SegmentEligibilityTest extends TestCase
{
    private SegmentEligibility $gate;

    protected function setUp(): void
    {
        $this->gate = new SegmentEligibility();
    }

    private function deliveredClosed(): ItemGate
    {
        // Delivered, window closed on 2026-01-01, no return.
        return new ItemGate('2026-01-01 00:00:00', '2026-01-08 00:00:00', SplitSegmentItem::RETURN_NONE);
    }

    public function testEligibleWhenAllItemsDeliveredAndWindowsClosed(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$this->deliveredClosed(), $this->deliveredClosed()],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertTrue($result->eligible);
        $this->assertNull($result->reason);
    }

    public function testBlockedWhenSegmentStateIsTerminal(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_RELEASED,
            [$this->deliveredClosed()],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('not releasable', (string) $result->reason);
    }

    /**
     * REFUND_PENDING is NEVER releasable — the state gate is its only protection. A write-ahead
     * REFUND_PENDING segment has an in-flight var8 but may carry NO refund leg yet (the leg is
     * recorded only after the PayU response), so the leg gate (hasBlockingRefund) cannot catch it.
     * Releasing it would disburse the whole held balance while a refund is about to debit it → over-pay.
     */
    public function testRefundPendingIsNeverReleasable(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_REFUND_PENDING,
            [$this->deliveredClosed()],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible, 'REFUND_PENDING must not be releasable');
        $this->assertStringContainsString('not releasable', (string) $result->reason);
    }

    /**
     * #12 — remainder release. PARTIAL_REFUNDED (a partial refund was ACCEPTED, bumping refunded_paise)
     * becomes releasable, but ONLY when no refund leg still blocks (hasBlockingRefund=false). This
     * supersedes the old A5c F-5 pin that blocked PARTIAL_REFUNDED via the STATE gate: the in-flight
     * protection now lives in the LEG gate (releaseBlockingLegs = PENDING/QUEUED/HELD). A completed
     * (SUCCESS) partial refund already debited the customer's portion from PayU's held balance, so
     * release_settlement disburses exactly the remainder (amount − refunded); an in-flight partial
     * refund (QUEUED/PENDING/HELD leg) still blocks via hasBlockingRefund. A false SUCCESS is caught
     * by the INV-18 readback (settled ≠ amount − refunded_paise → FREEZE), never a silent overpay.
     */
    public function testPartialRefundedIsReleasableOnlyWithoutABlockingRefund(): void
    {
        // SUCCESS-only / no in-flight leg (hasBlockingRefund=false) → the remainder may release.
        $eligible = $this->gate->evaluate(
            SplitSegment::STATE_PARTIAL_REFUNDED,
            [$this->deliveredClosed()],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertTrue($eligible->eligible, 'PARTIAL_REFUNDED with no blocking refund releases the remainder');

        // An in-flight partial refund (PENDING/QUEUED/HELD leg → hasBlockingRefund=true) still blocks.
        $blocked = $this->gate->evaluate(
            SplitSegment::STATE_PARTIAL_REFUNDED,
            [$this->deliveredClosed()],
            [],
            true,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($blocked->eligible, 'PARTIAL_REFUNDED with a blocking refund stays blocked');
        $this->assertStringContainsString('refund', (string) $blocked->reason);
    }

    public function testBlockedWhenSegmentHasAHold(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_ELIGIBLE,
            [$this->deliveredClosed()],
            ['RETURN_OPEN'],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('held', (string) $result->reason);
    }

    public function testBlockedWhenARefundIsPending(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$this->deliveredClosed()],
            [],
            true,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('refund', (string) $result->reason);
    }

    public function testBlockedWhenSegmentHasNoItems(): void
    {
        $result = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [], [], false, '2026-02-01 00:00:00');
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('no items', (string) $result->reason);
    }

    public function testBlockedWhenAnItemIsNotDelivered(): void
    {
        $undelivered = new ItemGate(null, null, SplitSegmentItem::RETURN_NONE);
        $result = $this->gate->evaluate(
            SplitSegment::STATE_AWAITING_DELIVERY,
            [$this->deliveredClosed(), $undelivered],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('not yet delivered', (string) $result->reason);
    }

    /**
     * F1-qty defense-in-depth. delivered_at is the primary truthful gate (the stamper sets it only
     * once delivered_qty >= ordered qty), but the gate ALSO refuses when the carried delivered_qty is
     * short of the ordered qty — so a stamping bug that set delivered_at prematurely on a partially-
     * shipped line (ship 1 of qty 2) can never leak the undelivered unit's escrow (the F1-qty
     * over-release). A strand is the safe failure direction.
     */
    public function testBlockedWhenAnItemIsOnlyPartiallyDelivered(): void
    {
        $partial = new ItemGate('2026-01-01 00:00:00', '2026-01-08 00:00:00', SplitSegmentItem::RETURN_NONE, 1, 2);
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$partial],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('partially delivered', (string) $result->reason);
    }

    public function testEligibleWhenDeliveredQtyMeetsOrderedQty(): void
    {
        // Full line delivered (2 of 2), window closed → releasable.
        $full = new ItemGate('2026-01-01 00:00:00', '2026-01-08 00:00:00', SplitSegmentItem::RETURN_NONE, 2, 2);
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$full],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertTrue($result->eligible);
    }

    public function testBlockedWhenAnItemHasAnOpenReturn(): void
    {
        $returning = new ItemGate('2026-01-01 00:00:00', '2026-01-08 00:00:00', SplitSegmentItem::RETURN_OPEN);
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$returning],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('open return', (string) $result->reason);
    }

    public function testBlockedWhenWindowStillOpenAndBoundaryIsStrict(): void
    {
        $item = new ItemGate('2026-01-01 00:00:00', '2026-01-08 12:00:00', SplitSegmentItem::RETURN_NONE);

        // now == window close: still open (strict boundary, matrix S-18).
        $atBoundary = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [$item], [], false, '2026-01-08 12:00:00');
        $this->assertFalse($atBoundary->eligible);
        $this->assertStringContainsString('window is still open', (string) $atBoundary->reason);

        // one second before close: still open.
        $before = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [$item], [], false, '2026-01-08 11:59:59');
        $this->assertFalse($before->eligible);

        // one second after close: eligible.
        $after = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [$item], [], false, '2026-01-08 12:00:01');
        $this->assertTrue($after->eligible);
    }
}
