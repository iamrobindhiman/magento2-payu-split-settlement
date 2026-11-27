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
 * re-running scan cannot pile up duplicate rows. The exists() check is the common-case optimisation;
 * the unique (segment_id, hold_type) index is the AUTHORITATIVE guard — under a concurrent place()
 * (e.g. order_cancel_after and mp_order_cancel_after firing for the same segment) two callers can
 * both pass exists() and both save(); the loser's insert is rejected by the index and treated here
 * as the intended no-op (the hold IS present), not an error.
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

        try {
            $this->holdResource->save($hold);
        } catch (\Throwable $e) {
            // Lost the exists()→save() race: a concurrent place() already inserted the same
            // (segment_id, hold_type) and the unique index rejected ours. The hold is present —
            // the desired end state — so swallow it as an idempotent no-op. Re-throw only if the
            // row is genuinely absent (a real save failure, not the dedup index doing its job).
            if (!$this->exists($segmentId, $holdType)) {
                throw $e;
            }
        }
    }

    /**
     * Remove a hold of $holdType from $segmentId — the break-glass clear path (gate item #11), the
     * write-side complement to place(). Loads the matching hold model(s) and deletes each via the
     * resource so the delete is ORM-idiomatic and guard-compliant (no raw SQL). Returns the number
     * of rows removed, so a caller can distinguish "cleared" from "nothing to clear" (0). The unique
     * (segment_id, hold_type) index means at most one row matches, but the loop stays correct if that
     * ever changes.
     *
     * @param int $segmentId
     * @param string $holdType One of SplitHold::TYPE_*
     * @return int Number of hold rows removed
     */
    public function remove(int $segmentId, string $holdType): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        $collection->addFieldToFilter('hold_type', $holdType);

        $removed = 0;
        foreach ($collection->getItems() as $hold) {
            $this->holdResource->delete($hold);
            $removed++;
        }
        return $removed;
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
