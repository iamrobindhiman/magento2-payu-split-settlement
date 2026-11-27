<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\WebkulRefundCloser;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #4 — on rkd_payu_segment_refunded (dispatched by the refund executor when a vendor
 * segment is terminalised REFUNDED/PARTIAL_REFUNDED), close that vendor in Webkul's ledger (flip its
 * payable saleslist rows non-payable). Thin: WebkulRefundCloser owns the idempotency, the
 * transaction, and its own error handling.
 *
 * Defensive Throwable guard: a close failure must never disrupt the refund executor that dispatched
 * this event. A failed close leaves the segment webkul_synced_at = null, and the reconcile scan
 * retries it — so the worst case is a deferred close, never a broken refund.
 */
class CloseRefundedSegmentLedger implements ObserverInterface
{
    /**
     * @param WebkulRefundCloser $closer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly WebkulRefundCloser $closer,
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
            $this->closer->closeRefunded($segment);
        } catch (\Throwable $t) {
            $this->logger->error(
                sprintf(
                    'RKD_PayuSplit: Webkul refund-close observer error for segment %d — left for reconcile — %s',
                    (int) $segment->getId(),
                    $t->getMessage()
                ),
                ['exception' => $t]
            );
        }
    }
}
