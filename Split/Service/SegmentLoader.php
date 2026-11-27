<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Loads the persisted settlement segments for one order (the per-(order,seller)
 * rows incl. the parent commission segment). A thin, guard-compliant wrapper over
 * the segment collection — the single read path the snapshot reader and the
 * trigger scan both use.
 */
class SegmentLoader
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param int $orderId Magento order entity id
     * @return SplitSegment[] All segments for the order, in insertion order
     */
    public function loadByOrderId(int $orderId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        return array_values($collection->getItems());
    }

    /**
     * @param int $segmentId
     * @return SplitSegment|null
     */
    public function loadById(int $segmentId): ?SplitSegment
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        $segment = $collection->getFirstItem();
        return $segment->getId() ? $segment : null;
    }
}
