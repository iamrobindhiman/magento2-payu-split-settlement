<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * Pure, immutable projection of one segment item's gating signals — the input to
 * {@see SegmentEligibility}. Decoupled from the persistence model so the release
 * gate is unit-testable without a database. Timestamps are 'Y-m-d H:i:s' GMT
 * strings (lexically comparable to a "now" of the same format).
 */
class ItemGate
{
    /**
     * @param string|null $deliveredAt When the item was delivered (null = not yet)
     * @param string|null $windowClosesAt When the return window closes (null = unknown/open)
     * @param string $returnState One of SplitSegmentItem::RETURN_*
     */
    public function __construct(
        public readonly ?string $deliveredAt,
        public readonly ?string $windowClosesAt,
        public readonly string $returnState
    ) {
    }
}
