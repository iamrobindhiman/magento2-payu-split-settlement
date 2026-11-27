<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitOrder\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitOrder;

/**
 * Loads the split headers the trigger scan still has work for: PENDING (never
 * split) and REQUESTED (wrote-ahead, recovery candidates). DONE / FAILED / SKIPPED
 * / NOT_REQUIRED are terminal for the scan and excluded.
 *
 * Bounded + oldest-first: at launch-gate scale orders sit PENDING for days, so the
 * backlog is normally large. The scan takes the oldest N headers per run (closest to
 * the T+N ceiling, so most urgent) and leaves the rest for the next tick — keeping
 * one cron run's memory and sequential gateway calls bounded. Thin, guard-compliant
 * wrapper over the header collection.
 */
class PendingSplitLoader
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
     * @return SplitOrder[]
     */
    public function loadUnfinished(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(
            'split_state',
            ['in' => [SplitOrder::STATE_PENDING, SplitOrder::STATE_REQUESTED]]
        );
        // Oldest paid first — the orders nearest the compliance ceiling go first. A
        // null paid_at (terminal-header edge) sorts ahead and is handled by the resolver.
        $collection->setOrder('paid_at', 'ASC');
        $collection->setPageSize($this->config->getScanBatchSize());
        return array_values($collection->getItems());
    }
}
