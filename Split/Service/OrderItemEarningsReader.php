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
            $items[] = new OrderItemShare(
                (int) $row['seller_id'],
                (int) $row['order_item_id'],
                (int) $row['mageproduct_id'],
                (int) (float) ($row['qty'] ?? '0'),
                Money::fromRupees((string) $row['item_earnings']),
                Money::fromRupees((string) ($row['commission'] ?? '0')),
                Money::fromRupees((string) ($row['tax'] ?? '0')),
                (bool) (int) ($row['tax_to_seller'] ?? 0)
            );
        }
        return $items;
    }
}
