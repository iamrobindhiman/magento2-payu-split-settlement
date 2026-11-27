<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Loads the release-recon work-list (A5c-5): every segment sitting in RELEASE_REQUESTED — the
 * state A5c-2's ReleaseProcessor leaves a segment in after firing release_settlement. BOTH
 * vendor and parent (commission) segments are included; the recon promotes either to RELEASED
 * uniformly once PayU confirms the settled amount (INV-18). Parent-last gating lives upstream in
 * ReleaseProcessor, not here.
 *
 * Bounded + oldest-requested-first: the segment nearest its strand-alert threshold goes first;
 * the rest wait for the next tick, keeping one run's memory and sequential gateway calls
 * bounded. A FROZEN-held segment can still appear here (it stays RELEASE_REQUESTED); the
 * reconciler skips it cheaply before any gateway call. Thin, guard-compliant collection wrapper.
 */
class ReleaseRequestedLoader
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
    public function loadRequested(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('state', SplitSegment::STATE_RELEASE_REQUESTED);
        // Oldest request first — closest to the strand-alert threshold, so most urgent.
        $collection->setOrder('release_requested_at', 'ASC');
        $collection->setPageSize($this->config->getScanBatchSize());
        return array_values($collection->getItems());
    }
}
