<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The validated output of a split calculation: each vendor's exact payout keyed
 * by seller_id, plus the platform's share (the remainder). Every amount is a
 * 2-decimal Money and the segments are guaranteed to sum to the grand total.
 */
class SplitResult
{
    /**
     * @param array<int, Money> $vendorSegments seller_id => payout
     * @param Money $platformAmount
     * @param Money $grandTotal
     */
    public function __construct(
        private readonly array $vendorSegments,
        private readonly Money $platformAmount,
        private readonly Money $grandTotal
    ) {
    }

    /**
     * @return array<int, Money>
     */
    public function vendorSegments(): array
    {
        return $this->vendorSegments;
    }

    /**
     * @return Money
     */
    public function platformAmount(): Money
    {
        return $this->platformAmount;
    }

    /**
     * @return Money
     */
    public function grandTotal(): Money
    {
        return $this->grandTotal;
    }

    /**
     * Sum of all vendor segments plus the platform share. Always equals the grand total.
     *
     * @return Money
     */
    public function total(): Money
    {
        $sum = $this->platformAmount;
        foreach ($this->vendorSegments as $amount) {
            $sum = $sum->add($amount);
        }
        return $sum;
    }
}
