<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitRefund;

/**
 * Loads the refund-recon work-list (gate item #6): every POLLABLE refund leg — one in QUEUED or
 * PENDING with a non-null payu_request_id — oldest-first, bounded by the scan batch size.
 *
 * The non-null request-id filter is load-bearing: a FULL_UNWIND obligation and an absent-parent
 * commission leg are written PENDING with a NULL request id (no PayU refund was fired for them), so
 * they cannot be polled by check_action_status_txnid and must never enter the work-list. HELD and
 * REARMED legs are likewise excluded (only QUEUED/PENDING are in flight). Thin, guard-compliant
 * collection wrapper, symmetric to ReleaseRequestedLoader.
 */
class RefundLegLoader
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
     * @return SplitRefund[]
     */
    public function loadPollable(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(
            'state',
            ['in' => [SplitRefund::STATE_QUEUED, SplitRefund::STATE_PENDING]]
        );
        // Only legs PayU can be polled for — excludes the NULL-request-id FULL_UNWIND / absent-parent
        // obligations.
        $collection->addFieldToFilter('payu_request_id', ['notnull' => true]);
        // Oldest first — closest to the strand-alert threshold, so most urgent.
        $collection->setOrder('created_at', 'ASC');
        $collection->setPageSize($this->config->getScanBatchSize());
        return array_values($collection->getItems());
    }
}
