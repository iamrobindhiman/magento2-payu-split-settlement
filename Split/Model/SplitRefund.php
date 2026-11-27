<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One PayU var8 refund leg (rkd_payu_refund). A single cancel_refund_transaction
 * call writes two of these — the child-merchant leg and the parent-commission leg
 * (proven by sandbox E-12) — each with its own payu_request_id used for status
 * polling. The leg lifecycle (PENDING → QUEUED → SUCCESS / FAILED) lives here.
 */
class SplitRefund extends AbstractModel
{
    public const STATE_PENDING = 'PENDING';
    public const STATE_QUEUED = 'QUEUED';
    public const STATE_SUCCESS = 'SUCCESS';
    public const STATE_FAILED = 'FAILED';

    /**
     * Auto-processing has been halted for this leg; it needs manual reconciliation. Recorded
     * as a freeze marker when the executor cannot safely continue: a PayU response carrying an
     * unexpected/duplicate merchant key it cannot attribute (R-7), or a refund PayU has
     * rejected past the retry cap (R-6). HELD is NON-FAILED, so it removes the segment from
     * the refund scan (no re-fire) and counts toward the idempotency set, but is semantically
     * distinct from PENDING so a future async reconciler must NOT auto-retry it.
     */
    public const STATE_HELD = 'HELD';

    /**
     * Voided by an admin re-arm (A7-3): a HELD freeze or a FAILED attempt that an operator has
     * deliberately cleared so the refund scan re-selects the segment and the executor re-fires
     * (on the SAME intent token, so PayU's var2-dedup remains the double-refund backstop).
     * REARMED is terminal and is excluded EVERYWHERE a prior attempt would block a retry — the
     * idempotency set ({@see RefundLedger::activeLegs()}), the R-6 cap count is naturally reset
     * (REARMED is not FAILED), and the refund scan's leg filter. It preserves the original leg
     * row (request id, reason) for the audit trail rather than deleting it.
     */
    public const STATE_REARMED = 'REARMED';

    public const REASON_VENDOR_CANCEL = 'VENDOR_CANCEL';
    public const REASON_CUSTOMER_CANCEL = 'CUSTOMER_CANCEL';
    public const REASON_RETURN = 'RETURN';
    public const REASON_RTO = 'RTO';
    public const REASON_LOST = 'LOST';
    public const REASON_FULL_UNWIND = 'FULL_UNWIND';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitRefundResource::class);
    }

    /**
     * @return int
     */
    public function getOrderId(): int
    {
        return (int) $this->getData('order_id');
    }

    /**
     * @return int
     */
    public function getSegmentId(): int
    {
        return (int) $this->getData('segment_id');
    }

    /**
     * @return int Leg amount (vendor-debit portion for a child leg, commission for a parent leg), paise
     */
    public function getAmountPaise(): int
    {
        return (int) $this->getData('amount_paise');
    }

    /**
     * @return int aggregatorRefundAmount portion, paise
     */
    public function getAggregatorPaise(): int
    {
        return (int) $this->getData('aggregator_paise');
    }

    /**
     * @return string|null PayU refund request id (the status-poll / idempotency anchor)
     */
    public function getPayuRequestId(): ?string
    {
        $value = $this->getData('payu_request_id');
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * @param string|null $requestId
     * @return $this
     */
    public function setPayuRequestId(?string $requestId): self
    {
        return $this->setData('payu_request_id', $requestId);
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return (string) $this->getData('state');
    }

    /**
     * @param string $state
     * @return $this
     */
    public function setState(string $state): self
    {
        return $this->setData('state', $state);
    }

    /**
     * @return string|null
     */
    public function getReason(): ?string
    {
        $value = $this->getData('reason');
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
