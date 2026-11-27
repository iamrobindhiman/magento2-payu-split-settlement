<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitOrder as SplitOrderResource;

/**
 * Loads a split header by order_id (the natural lookup for record-on-pay
 * idempotency) and persists it. Thin wrapper over the resource model; no
 * ResourceConnection (guard-compliant).
 */
class SplitOrderRepository implements SplitOrderRepositoryInterface
{
    /**
     * @param SplitOrderResource $resource
     * @param SplitOrderFactory $splitOrderFactory
     */
    public function __construct(
        private readonly SplitOrderResource $resource,
        private readonly SplitOrderFactory $splitOrderFactory
    ) {
    }

    /**
     * @param int $orderId
     * @return SplitOrder|null
     */
    public function getByOrderId(int $orderId): ?SplitOrder
    {
        $model = $this->splitOrderFactory->create();
        $this->resource->load($model, $orderId, 'order_id');
        return $model->getId() ? $model : null;
    }

    /**
     * @param SplitOrder $splitOrder
     * @return SplitOrder
     */
    public function save(SplitOrder $splitOrder): SplitOrder
    {
        $this->resource->save($splitOrder);
        return $splitOrder;
    }
}
