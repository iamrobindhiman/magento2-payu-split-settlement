<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitRefund;
use Magento\Framework\Exception\LocalizedException;

/**
 * Break-glass re-arm of a FROZEN refund (A7-3) — the money-critical recovery action.
 *
 * A segment's refund is frozen by a HELD leg after the R-6 retry cap or an R-7 response
 * anomaly, which removes it from the refund scan. Re-arm voids the segment's HELD + FAILED legs
 * to {@see SplitRefund::STATE_REARMED} — excluded from the idempotency set, the cap count, and
 * the scan's leg filter — so the next refund scan re-selects the segment and the executor
 * re-fires on the SAME intent token (so PayU's var2-dedup stays the double-refund backstop).
 *
 * Hard safety rule: it REFUSES if the segment carries any SUCCESS / QUEUED / PENDING leg — that
 * refund is already done or in flight, and re-firing it could double-refund the customer. Those
 * legs are never transitioned. It also refuses when nothing is actually frozen (no HELD leg).
 * It issues NO PayU call itself; the re-fire rides the reviewed refund executor + cron.
 */
class RefundRearmer
{
    /**
     * Leg states that mean a real refund is done or in flight — re-arm must not disturb them.
     */
    private const BLOCKING_STATES = [
        SplitRefund::STATE_SUCCESS,
        SplitRefund::STATE_QUEUED,
        SplitRefund::STATE_PENDING,
    ];

    /**
     * @param CollectionFactory $refundCollectionFactory
     * @param SplitRefundResource $refundResource
     */
    public function __construct(
        private readonly CollectionFactory $refundCollectionFactory,
        private readonly SplitRefundResource $refundResource
    ) {
    }

    /**
     * @param int $segmentId
     * @return int The number of legs voided to REARMED.
     * @throws LocalizedException
     */
    public function rearm(int $segmentId): int
    {
        $legs = $this->legsFor($segmentId);

        $hasHeld = false;
        foreach ($legs as $leg) {
            $state = $leg->getState();
            if (in_array($state, self::BLOCKING_STATES, true)) {
                throw new LocalizedException(__(
                    'Segment %1 has an in-flight or completed refund leg (%2); re-arm could '
                    . 'double-refund the customer — resolve it manually instead.',
                    $segmentId,
                    $state
                ));
            }
            if ($state === SplitRefund::STATE_HELD) {
                $hasHeld = true;
            }
        }

        if (!$hasHeld) {
            throw new LocalizedException(__(
                'Segment %1 has no frozen (HELD) refund to re-arm.',
                $segmentId
            ));
        }

        // Void all blocking legs atomically — a partial void (some REARMED, some not) would
        // leave the segment stranded (still blocked, or re-capped). The re-arm moves no money,
        // so a rollback safely leaves the segment in its prior frozen state.
        $connection = $this->refundResource->getConnection();
        $connection->beginTransaction();
        try {
            $voided = 0;
            foreach ($legs as $leg) {
                if (in_array($leg->getState(), [SplitRefund::STATE_HELD, SplitRefund::STATE_FAILED], true)) {
                    $leg->setState(SplitRefund::STATE_REARMED);
                    $this->refundResource->save($leg);
                    $voided++;
                }
            }
            $connection->commit();
            return $voided;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * @param int $segmentId
     * @return SplitRefund[]
     */
    private function legsFor(int $segmentId): array
    {
        $collection = $this->refundCollectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        return array_values($collection->getItems());
    }
}
