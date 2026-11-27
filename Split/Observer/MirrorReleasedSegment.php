<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\WebkulSettlementSync;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * A5c-4 — on rkd_payu_segment_released (dispatched by the A5c-5 recon when a segment is confirmed
 * RELEASED), mirror the payout into Webkul's seller ledger. Thin: the WebkulSettlementSync owns the
 * idempotency, the transaction, and its own error handling.
 *
 * Defensive Throwable guard: a mirror failure must never disrupt the recon that dispatched this
 * event. A failed mirror leaves the segment webkul_synced_at = null, and the pull-reconcile scan
 * retries it — so the worst case is a deferred mirror, never a broken release.
 */
class MirrorReleasedSegment implements ObserverInterface
{
    /**
     * @param WebkulSettlementSync $sync
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly WebkulSettlementSync $sync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $segment = $observer->getEvent()->getData('segment');
        if (!$segment instanceof SplitSegment) {
            return;
        }

        try {
            $this->sync->sync($segment);
        } catch (\Throwable $t) {
            $this->logger->error(
                sprintf(
                    'RKD_PayuSplit: Webkul mirror observer error for segment %d — left for reconcile — %s',
                    (int) $segment->getId(),
                    $t->getMessage()
                ),
                ['exception' => $t]
            );
        }
    }
}
