<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * A cancelled vendor segment the refund scan has selected for a var8 refund, paired with
 * the refund reason derived from the cancel actor (marketplace_orders.cancelled_by_type).
 * Immutable.
 */
class RefundCandidate
{
    /**
     * @param SplitSegment $segment The vendor segment to refund
     * @param string $reason One of SplitRefund::REASON_*
     */
    public function __construct(
        public readonly SplitSegment $segment,
        public readonly string $reason
    ) {
    }
}
