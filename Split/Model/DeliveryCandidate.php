<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * An undelivered vendor segment the delivery poll has selected: its segment id plus the
 * (order, seller) the Shiprocket provider needs to resolve the shipment. Immutable.
 */
class DeliveryCandidate
{
    /**
     * @param int $segmentId
     * @param int $orderId Magento sales_order.entity_id
     * @param int $sellerId
     */
    public function __construct(
        public readonly int $segmentId,
        public readonly int $orderId,
        public readonly int $sellerId
    ) {
    }
}
