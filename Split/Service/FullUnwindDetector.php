<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\FullUnwindResult;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Pure predicate for the O-9 Option C shipping true-up (LOCKED). An order has fully
 * unwound when EVERY vendor segment is in a customer-unwind-terminal state — cancelled
 * or refunded, the customer kept nothing. Only then is the retained shipping refunded
 * (a partial unwind keeps shipping in the parent).
 *
 * The make-whole amount is the customer-PAID SHIPPING (snapshotted at split), BOUNDED by
 * the parent's remaining balance — it is NOT the parent's whole remainder. The old
 * "remainder == shipping" assumption is false in two cases:
 *   - the parent holds un-clawed commission (a vendor refunded with aggregatorRefundAmount=0
 *     because the customer paid <= its payout, e.g. a deep discount / credit memo) → the
 *     remainder EXCEEDS shipping; refunding it would hand the customer commission they never
 *     paid (over-refund). We cap the top-up at the shipping.
 *   - a marketplace-funded discount was absorbed into the parent at split → the remainder is
 *     BELOW shipping; the parent can only fund part of it. We top up what it holds and report
 *     the rest as a shortfall (an operating-fund obligation), so the customer is never silently
 *     under-refunded.
 *
 * It must fire only at the LAST terminal event (S-37): a surviving released vendor, an
 * in-flight REFUND_PENDING leg, or a PARTIAL_REFUNDED segment all mean "not yet".
 */
class FullUnwindDetector
{
    /**
     * Vendor-segment states that count as a customer unwind (the customer received
     * nothing for that vendor). A released or still-in-flight (REFUND_PENDING) segment is
     * NOT terminal here; a written-off segment is a marketplace recovery, not a customer
     * refund. PARTIAL_REFUNDED counts as terminal in the v1 CANCELLATION scope: a cancel
     * that lands partial only because the customer paid less than the vendor payout (deep
     * discount) is fully done — nothing more is owed. ⚠️ v2 RETURNS must distinguish
     * "partial-refunded but customer made whole" from "partial-refunded with more owed"
     * before reusing this predicate for returns.
     */
    private const UNWIND_TERMINAL_STATES = [
        SplitSegment::STATE_CANCELLED,
        SplitSegment::STATE_REFUNDED,
        SplitSegment::STATE_PARTIAL_REFUNDED,
    ];

    /**
     * @param string[] $vendorSegmentStates State of each vendor (non-parent) segment on the order
     * @param int $parentRemainingPaise Parent segment's unrefunded balance, paise
     * @param int $shippingPaise Customer-paid shipping snapshotted at split, paise (the amount owed)
     * @return FullUnwindResult
     */
    public function detect(array $vendorSegmentStates, int $parentRemainingPaise, int $shippingPaise): FullUnwindResult
    {
        // An order with no vendor segments is not an unwind — never top up an empty set.
        if ($vendorSegmentStates === []) {
            return new FullUnwindResult(false, 0, 0);
        }

        foreach ($vendorSegmentStates as $state) {
            if (!in_array($state, self::UNWIND_TERMINAL_STATES, true)) {
                return new FullUnwindResult(false, 0, 0);
            }
        }

        // Fully unwound (Option C): the customer is owed the shipping they paid. The parent can
        // fund up to its remaining balance; cap the PayU top-up there (so un-clawed commission is
        // never refunded), and report any unfundable remainder as an operating-fund shortfall.
        $owed = $shippingPaise > 0 ? $shippingPaise : 0;
        $fundable = $parentRemainingPaise > 0 ? $parentRemainingPaise : 0;
        $topUp = min($owed, $fundable);
        $shortfall = $owed > $fundable ? $owed - $fundable : 0;

        return new FullUnwindResult(true, $topUp, $shortfall);
    }
}
