<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\SplitOrder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Break-glass re-arm for a split header the trigger scan terminalised as FAILED (A7-3).
 *
 * Flips FAILED → PENDING (and clears the stale error) so the next split-trigger scan
 * re-attempts. It issues NO PayU call — the re-attempt rides the reviewed
 * {@see SplitRequester::request()}, which calls get_split_info first, so a split that actually
 * succeeded resolves to DONE (AGG-104) rather than firing a second payment_split. Any
 * non-FAILED header is refused, so the action can never disturb a healthy or in-flight split.
 */
class SplitRearmer
{
    /**
     * @param SplitOrderRepositoryInterface $splitOrderRepository
     */
    public function __construct(
        private readonly SplitOrderRepositoryInterface $splitOrderRepository
    ) {
    }

    /**
     * @param SplitOrder $header
     * @return void
     * @throws LocalizedException
     */
    public function rearm(SplitOrder $header): void
    {
        if ($header->getSplitState() !== SplitOrder::STATE_FAILED) {
            throw new LocalizedException(
                __('Only a FAILED split can be retried (current state: %1).', $header->getSplitState())
            );
        }

        $header->setSplitState(SplitOrder::STATE_PENDING);
        $header->setData('last_error', null);
        $this->splitOrderRepository->save($header);
    }
}
