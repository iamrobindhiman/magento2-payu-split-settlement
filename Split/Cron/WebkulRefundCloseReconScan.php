<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Service\UnsyncedRefundedLoader;
use RKD\PayuSplit\Split\Service\WebkulRefundCloser;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #4 — the Webkul refund-close reconcile scan: the self-heal backstop for the
 * rkd_payu_segment_refunded event. For each REFUNDED/PARTIAL_REFUNDED vendor segment not yet closed
 * (webkul_synced_at IS NULL) it re-drives WebkulRefundCloser, so a refund whose close event was lost
 * (a crash between the refund commit and the listener) still removes the vendor from Webkul's payable
 * set. Idempotent (the closer no-ops a segment already closed), per-segment isolated.
 *
 * NOT gated by the release kill-switch: refunds (the A5b cancellation path) run independently of the
 * release path, so their ledger close must too — the over-pay risk exists the moment a vendor is
 * refunded, regardless of whether the release executor is switched on. Mirrors the ungated refund
 * executor scan.
 */
class WebkulRefundCloseReconScan
{
    /**
     * @param UnsyncedRefundedLoader $loader
     * @param WebkulRefundCloser $closer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly UnsyncedRefundedLoader $loader,
        private readonly WebkulRefundCloser $closer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->loader->loadUnsynced() as $segment) {
            try {
                $this->closer->closeRefunded($segment);
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: Webkul refund-close reconcile error for segment %d (order %d, will retry) — %s',
                        (int) $segment->getId(),
                        $segment->getOrderId(),
                        $t->getMessage()
                    ),
                    ['exception' => $t]
                );
            }
        }
    }
}
