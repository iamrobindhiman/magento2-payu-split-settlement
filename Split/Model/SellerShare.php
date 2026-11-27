<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * One seller's contribution to an order, as already computed by Webkul.
 * `itemEarnings` is the vendor's net item payout (Webkul's actual_seller_amount:
 * item subtotal minus commission and coupon). Tax is added to the payout only
 * when the order's tax_to_seller flag is set; shipping is never included
 * (the platform keeps 100% of shipping).
 */
class SellerShare
{
    /**
     * @param int $sellerId
     * @param Money $itemEarnings Vendor's net item payout (actual_seller_amount)
     * @param Money $tax Tax on this seller's items (total_tax)
     * @param bool $taxToSeller Whether tax flows to the vendor (Webkul tax_to_seller flag)
     */
    public function __construct(
        public readonly int $sellerId,
        public readonly Money $itemEarnings,
        public readonly Money $tax,
        public readonly bool $taxToSeller
    ) {
    }

    /**
     * The amount this vendor should receive: item earnings, plus tax when the
     * tax_to_seller flag is on.
     *
     * @return Money
     */
    public function payout(): Money
    {
        return $this->taxToSeller ? $this->itemEarnings->add($this->tax) : $this->itemEarnings;
    }
}
