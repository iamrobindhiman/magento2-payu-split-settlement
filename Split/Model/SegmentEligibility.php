<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The canonical per-vendor release gate (settlement matrix INV-2/INV-10/INV-12/INV-19).
 *
 * A vendor segment is releasable only when ALL hold:
 *   - the segment is in a pre-release state (not terminal, not in-flight),
 *   - it carries no hold (KYC suspension, open return, replacement, manual),
 *   - no refund leg on it is still PENDING/QUEUED,
 *   - it has at least one item, and EVERY item is delivered, past its own return
 *     window (strictly), and free of an open return.
 *
 * Pure: it takes plain gating data plus a "now" timestamp, so there is no DB or
 * clock dependency — the same function the release cron (A5c) and the admin grid
 * (A7) both consult, kept here as the single source of truth.
 */
class SegmentEligibility
{
    /** Segment states from which a release may still proceed (non-terminal, not in-flight). */
    private const RELEASABLE_STATES = [
        SplitSegment::STATE_PENDING,
        SplitSegment::STATE_AWAITING_DELIVERY,
        SplitSegment::STATE_IN_WINDOW,
        SplitSegment::STATE_ELIGIBLE,
    ];

    /**
     * @param string $segmentState Current segment state
     * @param ItemGate[] $items The segment's item gates (a vendor segment must have at least one)
     * @param string[] $holdTypes Active hold types on the segment (empty = no holds)
     * @param bool $hasPendingRefund Whether any refund leg on the segment is PENDING/QUEUED
     * @param string $nowGmt Current time as 'Y-m-d H:i:s' GMT (comparable to window_closes_at)
     * @return EligibilityResult
     */
    public function evaluate(
        string $segmentState,
        array $items,
        array $holdTypes,
        bool $hasPendingRefund,
        string $nowGmt
    ): EligibilityResult {
        if (!in_array($segmentState, self::RELEASABLE_STATES, true)) {
            return EligibilityResult::blocked(sprintf('segment state %s is not releasable', $segmentState));
        }
        if ($holdTypes !== []) {
            return EligibilityResult::blocked('segment is held: ' . implode(',', $holdTypes));
        }
        if ($hasPendingRefund) {
            return EligibilityResult::blocked('a refund on the segment is still pending');
        }
        if ($items === []) {
            return EligibilityResult::blocked('segment has no items to gate');
        }

        foreach ($items as $item) {
            if ($item->deliveredAt === null) {
                return EligibilityResult::blocked('an item is not yet delivered');
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
