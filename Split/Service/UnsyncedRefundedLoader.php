<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Loads the Webkul refund-close reconcile work-list (gate blocker #4): VENDOR segments that the
 * refund executor terminalised REFUNDED/PARTIAL_REFUNDED but whose Webkul ledger close was not yet
 * applied (webkul_synced_at IS NULL). Normally the rkd_payu_segment_refunded event closes a segment
 * the instant it is refunded; this scan is the backstop that re-drives any segment whose event was
 * lost (a crash between the refund commit and the listener) — so a lost dispatch self-heals.
 *
 * Vendor-only: the commission parent has no Webkul seller ledger, so it is never closed (and reaches
 * PARTIAL_REFUNDED in normal operation, which must not be picked up here). Bounded + oldest first.
 */
class UnsyncedRefundedLoader
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
        $collection->addFieldToFilter(
            'state',
            ['in' => [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_PARTIAL_REFUNDED]]
        );
        $collection->addFieldToFilter('segment_type', SplitSegment::TYPE_VENDOR);
        $collection->addFieldToFilter('webkul_synced_at', ['null' => true]);
        $collection->setOrder('updated_at', 'ASC');
        $collection->setPageSize($this->config->getScanBatchSize());
        return array_values($collection->getItems());
    }
}
