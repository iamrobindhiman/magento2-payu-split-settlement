<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitRefundFactory;

/**
 * The refund-leg ledger (rkd_payu_refund). It records one row per PayU var8 response
 * leg and answers the executor's idempotency question — does this segment already have a
 * refund leg that is not FAILED? A non-FAILED leg (PENDING/QUEUED/SUCCESS) means the
 * refund is already in flight or done, so the executor must never fire a second var8.
 */
class RefundLedger
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param SplitRefundFactory $refundFactory
     * @param SplitRefundResource $refundResource
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SplitRefundFactory $refundFactory,
        private readonly SplitRefundResource $refundResource
    ) {
    }

    /**
     * The non-FAILED refund legs for a segment — the idempotency set. Empty means no
     * refund has been accepted (a fresh segment, or one whose only legs are FAILED).
     *
     * @param int $segmentId
     * @return SplitRefund[]
     */
    public function activeLegs(int $segmentId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        // FAILED legs never blocked a retry; REARMED legs were deliberately voided by an admin
        // re-arm (A7-3) — both must be excluded so the executor re-fires after a re-arm.
        $collection->addFieldToFilter(
            'state',
            ['nin' => [SplitRefund::STATE_FAILED, SplitRefund::STATE_REARMED]]
        );
        return array_values($collection->getItems());
    }

    /**
     * The number of FAILED refund legs a segment has accumulated — the ledger-derived retry
     * count for the R-6 cap (no schema column). Each rejected var8 tick writes one FAILED
     * child leg, so this is the count of prior failed attempts.
     *
     * @param int $segmentId
     * @return int
     */
    public function failedLegCount(int $segmentId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        $collection->addFieldToFilter('state', SplitRefund::STATE_FAILED);
        return $collection->getSize();
    }

    /**
     * Persist one refund leg.
     *
     * @param int $orderId
     * @param int $segmentId The segment whose escrow this leg debits (vendor leg → vendor segment, parent leg → parent)
     * @param int $amountPaise Paise debited from this leg's merchant (0 for a pure aggregator leg)
     * @param int $aggregatorPaise aggregatorRefundAmount portion, paise (0 for a child leg)
     * @param string|null $requestId PayU refund request id (the status-poll anchor)
     * @param string $state One of SplitRefund::STATE_*
     * @param string $reason One of SplitRefund::REASON_*
     * @return SplitRefund
     */
    public function recordLeg(
        int $orderId,
        int $segmentId,
        int $amountPaise,
        int $aggregatorPaise,
        ?string $requestId,
        string $state,
        string $reason
    ): SplitRefund {
        /** @var SplitRefund $leg */
        $leg = $this->refundFactory->create();
        $leg->setData([
            'order_id' => $orderId,
            'segment_id' => $segmentId,
            'amount_paise' => $amountPaise,
            'aggregator_paise' => $aggregatorPaise,
            'payu_request_id' => $requestId,
            'state' => $state,
            'reason' => $reason,
        ]);
        $this->refundResource->save($leg);
        return $leg;
    }
}
