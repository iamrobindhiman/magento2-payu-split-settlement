<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\ResourceModel\SellerEarnings as SellerEarningsResource;
use RKD\PayuSplit\Split\Model\SellerShare;

/**
 * Turns Webkul's aggregated per-seller earnings rows into typed SellerShare
 * value objects (paise-safe Money + the tax_to_seller flag) for the calculator.
 */
class SellerEarningsReader
{
    /**
     * @param SellerEarningsResource $resource
     */
    public function __construct(
        private readonly SellerEarningsResource $resource
    ) {
    }

    /**
     * @param int $orderId
     * @return SellerShare[]
     */
    public function read(int $orderId): array
    {
        $shares = [];
        foreach ($this->resource->getSellerTotals($orderId) as $row) {
            $shares[] = new SellerShare(
                (int) $row['seller_id'],
                Money::fromRupees((string) $row['item_earnings']),
                Money::fromRupees((string) ($row['tax'] ?? '0')),
                (bool) (int) ($row['tax_to_seller'] ?? 0)
            );
        }
        return $shares;
    }
}
