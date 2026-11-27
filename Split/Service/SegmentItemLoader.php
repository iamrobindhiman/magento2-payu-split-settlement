<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem\CollectionFactory;

/**
 * Loads the persisted per-item gating rows for a segment and projects out their
 * sales_order_item ids — the keys the paid-amount reader needs to price the customer's
 * refund from Magento's immutable order totals. A thin, guard-compliant wrapper over the
 * segment-item collection (smoke-covered via the live refund test).
 */
class SegmentItemLoader
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param int $segmentId
     * @return int[] sales_order_item ids for the segment's lines (skips unset/0 ids)
     */
    public function loadOrderItemIds(int $segmentId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);

        $ids = [];
        foreach ($collection->getItems() as $item) {
            $orderItemId = (int) $item->getOrderItemId();
            if ($orderItemId > 0) {
                $ids[] = $orderItemId;
            }
        }
        return $ids;
    }

    /**
     * The full per-item gating rows for a segment — the release gate projects each to an
     * ItemGate (delivered_at / window_closes_at / return_state) to decide eligibility.
     *
     * @param int $segmentId
     * @return \RKD\PayuSplit\Split\Model\SplitSegmentItem[]
     */
    public function loadItems(int $segmentId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        return array_values($collection->getItems());
    }
}
