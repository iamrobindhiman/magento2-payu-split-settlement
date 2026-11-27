<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\SplitOrder;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The split header's lifecycle transitions, in one place. The trigger scan and the
 * split requester only ever move a header through these methods, so every state
 * change stamps the right timestamp and persists exactly once — no scattered
 * setData/save pairs drifting out of step.
 *
 * Transitions: PENDING → REQUESTED (write-ahead, before the POST) → DONE. FAILED is
 * reachable from any non-terminal state. keepRequested() records a transient reason
 * (PENDING split / AGG-110 lock) without leaving REQUESTED, so the next scan retries
 * via the idempotency path.
 */
class SplitStateMachine
{
    /**
     * @param SplitOrderRepositoryInterface $repository
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Write-ahead the intent to split: the header records REQUESTED and the request
     * timestamp before the gateway POST, so a crash-and-retry is recoverable.
     *
     * @param SplitOrder $header
     * @return void
     */
    public function markRequested(SplitOrder $header): void
    {
        $header->setSplitState(SplitOrder::STATE_REQUESTED);
        $header->setData('split_requested_at', $this->dateTime->gmtDate());
        $this->repository->save($header);
    }

    /**
     * The split exists at PayU (fresh success or AGG-104 idempotency). Stamp DONE,
     * store the get_split_info readback for audit, and clear any transient error.
     *
     * @param SplitOrder $header
     * @param string|null $readback Serialised get_split_info response, or null if unavailable
     * @return void
     */
    public function markDone(SplitOrder $header, ?string $readback): void
    {
        $header->setSplitState(SplitOrder::STATE_DONE);
        $header->setData('split_done_at', $this->dateTime->gmtDate());
        if ($readback !== null) {
            $header->setData('payu_split_response', $readback);
        }
        $header->setData('last_error', null);
        $this->repository->save($header);
    }

    /**
     * @param SplitOrder $header
     * @param string $error
     * @return void
     */
    public function markFailed(SplitOrder $header, string $error): void
    {
        $header->setSplitState(SplitOrder::STATE_FAILED);
        $header->setData('last_error', $error);
        $this->repository->save($header);
    }

    /**
     * Record a transient reason (no mihpayid yet / PENDING split / transient lock)
     * and persist it WITHOUT changing the current state — the next scan retries the
     * same header (a PENDING one re-attempts; a REQUESTED one recovers via
     * get_split_info). State-agnostic on purpose.
     *
     * @param SplitOrder $header
     * @param string $reason
     * @return void
     */
    public function recordTransient(SplitOrder $header, string $reason): void
    {
        $header->setData('last_error', $reason);
        $this->repository->save($header);
    }
}
