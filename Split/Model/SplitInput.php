<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * Immutable input to the split calculation: the order's grand total (the exact
 * amount the customer paid, which all segments must sum to) and the per-seller
 * shares that make it up.
 */
class SplitInput
{
    /**
     * @param Money $grandTotal
     * @param SellerShare[] $sellerShares
     */
    public function __construct(
        private readonly Money $grandTotal,
        private readonly array $sellerShares
    ) {
    }

    /**
     * @return Money
     */
    public function grandTotal(): Money
    {
        return $this->grandTotal;
    }

    /**
     * @return SellerShare[]
     */
    public function sellerShares(): array
    {
        return $this->sellerShares;
    }
}
