<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold\CollectionFactory;

/**
 * Loads the active hold-type strings on a segment (rkd_payu_segment_hold) so the release gate
 * can refuse while any hold is present. A thin, guard-compliant wrapper over the hold
 * collection (smoke-covered via the live release test).
 */
class SegmentHoldLoader
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
     * @return string[] Active hold types on the segment (empty when releasable)
     */
    public function holdTypes(int $segmentId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);

        $types = [];
        foreach ($collection->getItems() as $hold) {
            $types[] = $hold->getHoldType();
        }
        return $types;
    }
}
