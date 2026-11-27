<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold as SplitHoldResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitHoldFactory;

/**
 * Places a hold on a segment (rkd_payu_segment_hold) — the write side that complements
 * SegmentHoldLoader's reads. The recon uses it to FROZE a segment whose post-release readback
 * did not match the expected net (INV-18): a frozen segment is never auto-released again and
 * must be reconciled by a human.
 *
 * Idempotent on (segment_id, hold_type): a second place() of the same hold is a no-op, so a
 * re-running scan cannot pile up duplicate rows (the table is also uniquely constrained).
 */
class SegmentHoldWriter
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param SplitHoldFactory $holdFactory
     * @param SplitHoldResource $holdResource
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SplitHoldFactory $holdFactory,
        private readonly SplitHoldResource $holdResource
    ) {
    }

    /**
     * Record a hold of $holdType on $segmentId. No-op if that hold already exists.
     *
     * @param int $segmentId
     * @param string $holdType One of SplitHold::TYPE_*
     * @param string|null $note Human-readable context for the hold
     * @return void
     */
    public function place(int $segmentId, string $holdType, ?string $note = null): void
    {
        if ($this->exists($segmentId, $holdType)) {
            return;
        }

        $hold = $this->holdFactory->create();
        $hold->addData([
            'segment_id' => $segmentId,
            'hold_type' => $holdType,
            'note' => $note,
        ]);
        $this->holdResource->save($hold);
    }

    /**
     * @param int $segmentId
     * @param string $holdType
     * @return bool
     */
    private function exists(int $segmentId, string $holdType): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        $collection->addFieldToFilter('hold_type', $holdType);
        return $collection->getSize() > 0;
    }
}
