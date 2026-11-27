<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The verdict of the full-unwind check on an order: whether every vendor segment is
 * terminal (the customer received nothing) and, if so, the O-9 Option C shipping make-whole
 * split into two parts. topUpPaise is what the parent's remaining balance can fund toward
 * the customer-paid shipping (the PayU-side top-up); shortfallPaise is any remainder of the
 * shipping the parent cannot cover (a marketplace-funded discount was absorbed into the
 * parent), owed to the customer from operating funds and surfaced — never silently dropped.
 * Immutable.
 */
class FullUnwindResult
{
    /**
     * @param bool $isFullUnwind True when every vendor segment on the order is cancelled/refunded
     * @param int $topUpPaise Shipping the parent can fund toward the customer, paise (0 unless a full unwind)
     * @param int $shortfallPaise Shipping the parent cannot fund (operating-fund obligation), paise
     */
    public function __construct(
        public readonly bool $isFullUnwind,
        public readonly int $topUpPaise,
        public readonly int $shortfallPaise = 0
    ) {
    }
}
