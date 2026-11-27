<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * One leg of a PayU cancel_refund_transaction response. A single child-keyed var8
 * call yields two legs (proven by sandbox E-12): the child-merchant refund and the
 * parent-commission refund, each with its own request_id used for status polling.
 * Immutable.
 */
class RefundLeg
{
    /**
     * @param string $merchantKey The PayU merchant key this leg debited (child or parent)
     * @param int $status PayU per-leg status (1 = queued/accepted)
     * @param string|null $requestId PayU refund request id (the idempotency/poll anchor); null when not accepted
     * @param string|null $errorCode PayU error/status code (102 accompanies a queued refund — not a failure)
     * @param string $message Human-readable message
     */
    public function __construct(
        public readonly string $merchantKey,
        public readonly int $status,
        public readonly ?string $requestId,
        public readonly ?string $errorCode,
        public readonly string $message
    ) {
    }

    /**
     * PayU accepts/queues a refund leg with status 1. A request_id is then issued.
     *
     * @return bool
     */
    public function isAccepted(): bool
    {
        return $this->status === 1;
    }
}
