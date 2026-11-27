<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * One order line's per-seller breakdown, read from Webkul's marketplace_saleslist
 * (one row per sold item). Mirrors {@see SellerShare}'s payout rule at the item
 * grain so that the sum of a seller's item payouts equals that seller's aggregated
 * split segment — which is what lets the settlement model gate release per item
 * while still moving money per vendor segment. Immutable.
 */
class OrderItemShare
{
    /**
     * @param int $sellerId Webkul seller (customer) id
     * @param int $orderItemId Magento sales_order_item id (gating key for delivery/returns)
     * @param int $mageproductId Catalog product id
     * @param int $qty Ordered quantity for this line
     * @param Money $itemEarnings Vendor's net for this line (actual_seller_amount)
     * @param Money $commission Marketplace commission on this line (total_commission)
     * @param Money $tax Tax on this line (total_tax)
     * @param bool $taxToSeller Whether tax flows to the vendor (order tax_to_seller flag)
     */
    public function __construct(
        public readonly int $sellerId,
        public readonly int $orderItemId,
        public readonly int $mageproductId,
        public readonly int $qty,
        public readonly Money $itemEarnings,
        public readonly Money $commission,
        public readonly Money $tax,
        public readonly bool $taxToSeller
    ) {
    }

    /**
     * The vendor's payout for this line: item earnings, plus tax when the
     * tax_to_seller flag is on (shipping is never included — the platform keeps it).
     *
     * @return Money
     */
    public function payout(): Money
    {
        return $this->taxToSeller ? $this->itemEarnings->add($this->tax) : $this->itemEarnings;
    }
}
