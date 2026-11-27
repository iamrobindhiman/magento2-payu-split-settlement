<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderItemShare;
use RKD\PayuSplit\Split\Model\ResourceModel\OrderItemEarnings as OrderItemEarningsResource;

/**
 * Turns Webkul's per-item marketplace_saleslist rows into typed OrderItemShare
 * value objects (paise-safe Money) for one order. The per-item siblings of the
 * per-seller totals {@see SellerEarningsReader} produces — used to populate the
 * per-item gating rows of a split plan.
 */
class OrderItemEarningsReader
{
    /**
     * @param OrderItemEarningsResource $resource
     */
    public function __construct(
        private readonly OrderItemEarningsResource $resource
    ) {
    }

    /**
     * @param int $orderId
     * @return OrderItemShare[]
     */
    public function read(int $orderId): array
    {
        $items = [];
        foreach ($this->resource->getItemRows($orderId) as $row) {
            // magequantity is a varchar. A non-integer ordered qty truncates DOWN to the segment's int
            // qty; combined with the delivered-side truncation that would under-count the release
            // threshold and over-release (gate item #22). Flag it so the split-record path freezes the
            // segment rather than gating it on a truncated qty.
            $rawQty = (float) ($row['qty'] ?? '0');
            $items[] = new OrderItemShare(
                (int) $row['seller_id'],
                (int) $row['order_item_id'],
                (int) $row['mageproduct_id'],
                (int) $rawQty,
                Money::fromRupees((string) $row['item_earnings']),
                Money::fromRupees((string) ($row['commission'] ?? '0')),
                Money::fromRupees((string) ($row['tax'] ?? '0')),
                (bool) (int) ($row['tax_to_seller'] ?? 0),
                $rawQty !== (float) (int) $rawQty
            );
        }
        return $items;
    }
}
