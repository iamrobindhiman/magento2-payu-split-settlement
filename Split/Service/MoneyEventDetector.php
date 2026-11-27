<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;

/**
 * Detects whether an order has a pending money event that requires its split to be
 * brought forward. Per Fact 4 a customer refund (var8) is only valid once the split
 * exists, so a cancelled vendor portion on a not-yet-split order must trigger the split
 * early (the MONEY_EVENT trigger) rather than waiting for ship-based / ceiling triggers.
 *
 * Cancellation scope: a money event is a cancelled vendor portion
 * (marketplace_orders.is_canceled = 1). Returns (deferred) will extend this seam.
 */
class MoneyEventDetector
{
    /**
     * @param SellerOrderStatus $sellerOrderStatus
     */
    public function __construct(
        private readonly SellerOrderStatus $sellerOrderStatus
    ) {
    }

    /**
     * @param int $orderId Magento order entity id
     * @return bool Whether a refund-pending cancel exists on the order
     */
    public function isMoneyEvent(int $orderId): bool
    {
        return $this->sellerOrderStatus->hasCanceledSeller($orderId);
    }
}
