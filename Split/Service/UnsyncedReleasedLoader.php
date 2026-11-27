<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Loads the Webkul-mirror reconcile work-list (A5c-4): segments that are RELEASED but whose payout
 * was not yet mirrored into Webkul's ledger (webkul_synced_at IS NULL). Normally the
 * rkd_payu_segment_released event mirrors a segment the instant it is released; this scan is the
 * backstop that re-drives any segment whose event was lost (a crash between the RELEASED commit and
 * the listener) — so a lost dispatch self-heals.
 *
 * Bounded + oldest-released first. Thin, guard-compliant collection wrapper.
 */
class UnsyncedReleasedLoader
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param SplitConfig $config
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SplitConfig $config
    ) {
    }

    /**
     * @return SplitSegment[]
     */
    public function loadUnsynced(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('state', SplitSegment::STATE_RELEASED);
        $collection->addFieldToFilter('webkul_synced_at', ['null' => true]);
        $collection->setOrder('released_at', 'ASC');
        $collection->setPageSize($this->config->getScanBatchSize());
        return array_values($collection->getItems());
    }
}
