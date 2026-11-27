<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The canonical per-vendor release gate (settlement matrix INV-2/INV-10/INV-12/INV-19).
 *
 * A vendor segment is releasable only when ALL hold:
 *   - the segment is in a pre-release state (not terminal, not in-flight),
 *   - it carries no hold (KYC suspension, open return, replacement, manual),
 *   - no refund leg on it still BLOCKS release (PENDING/QUEUED/HELD; a completed SUCCESS does not — #12),
 *   - it has at least one item, and EVERY item is delivered, past its own return
 *     window (strictly), and free of an open return.
 *
 * Pure: it takes plain gating data plus a "now" timestamp, so there is no DB or
 * clock dependency — the same function the release cron (A5c) and the admin grid
 * (A7) both consult, kept here as the single source of truth.
 */
class SegmentEligibility
{
    /**
     * Segment states from which a release may still proceed (non-terminal, not a write-ahead
     * in-flight refund). PARTIAL_REFUNDED is releasable (gate item #12: release the remainder the
     * customer kept) — its in-flight protection is the LEG gate (hasBlockingRefund), NOT this state
     * set: a completed partial refund already debited PayU's held balance, so the remainder is genuinely
     * owed. REFUND_PENDING is deliberately EXCLUDED — a write-ahead REFUND_PENDING may carry no leg yet,
     * so the state gate is its only in-flight protection.
     */
    private const RELEASABLE_STATES = [
        SplitSegment::STATE_PENDING,
        SplitSegment::STATE_AWAITING_DELIVERY,
        SplitSegment::STATE_IN_WINDOW,
        SplitSegment::STATE_ELIGIBLE,
        SplitSegment::STATE_PARTIAL_REFUNDED,
    ];

    /**
     * @param string $segmentState Current segment state
     * @param ItemGate[] $items The segment's item gates (a vendor segment must have at least one)
     * @param string[] $holdTypes Active hold types on the segment (empty = no holds)
     * @param bool $hasBlockingRefund Whether any refund leg on the segment still BLOCKS a release
     *        (PENDING/QUEUED/HELD — a completed SUCCESS refund does NOT block; gate item #12)
     * @param string $nowGmt Current time as 'Y-m-d H:i:s' GMT (comparable to window_closes_at)
     * @return EligibilityResult
     */
    public function evaluate(
        string $segmentState,
        array $items,
        array $holdTypes,
        bool $hasBlockingRefund,
        string $nowGmt
    ): EligibilityResult {
        if (!in_array($segmentState, self::RELEASABLE_STATES, true)) {
            return EligibilityResult::blocked(sprintf('segment state %s is not releasable', $segmentState));
        }
        if ($holdTypes !== []) {
            return EligibilityResult::blocked('segment is held: ' . implode(',', $holdTypes));
        }
        if ($hasBlockingRefund) {
            return EligibilityResult::blocked('a refund on the segment is still in flight');
        }
        if ($items === []) {
            return EligibilityResult::blocked('segment has no items to gate');
        }

        foreach ($items as $item) {
            if ($item->deliveredAt === null) {
                return EligibilityResult::blocked('an item is not yet delivered');
            }
            // F1-qty defense-in-depth: delivered_at set but the delivered qty is short of the ordered
            // qty (a partial-quantity shipment — ship 1 of qty 2) must NOT release. delivered_at is the
            // stamper's truthful gate (set only at full qty); this is the redundant backstop against a
            // stamping bug. Inert when orderedQty is unpopulated (0), so a strand — never an over-release.
            if ($item->orderedQty > 0 && $item->deliveredQty < $item->orderedQty) {
                return EligibilityResult::blocked('an item is only partially delivered');
            }
            if ($item->returnState === SplitSegmentItem::RETURN_OPEN) {
                return EligibilityResult::blocked('an item has an open return');
            }
            // Strict: the window must be CLOSED — now must be strictly after window_closes_at
            // (matrix S-18 boundary). A null window means it was never set => still open.
            if ($item->windowClosesAt === null || strcmp($nowGmt, $item->windowClosesAt) <= 0) {
                return EligibilityResult::blocked('an item return window is still open');
            }
        }

        return EligibilityResult::eligible();
    }
}
