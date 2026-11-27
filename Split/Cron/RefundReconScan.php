<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Service\RefundLegLoader;
use RKD\PayuSplit\Split\Service\RefundReconciler;
use Psr\Log\LoggerInterface;

/**
 * The refund-status reconciliation scan (gate item #6). For each pollable refund leg (QUEUED/PENDING
 * with a request id) it polls check_action_status_txnid and resolves the leg to SUCCESS or FAILED,
 * or strand-alarms a stuck one — the path that closes M-5 (a refund PayU queues-then-fails no longer
 * sits unconfirmed forever, silently leaving the customer unrefunded).
 *
 * UNGATED, mirroring RefundExecutorScan: refunds — and therefore their status reconciliation — run
 * pre-release, independent of the release kill-switch (rkd_payu/split/release_enabled). In UAT
 * check_action_status returns a generic error (E-12), so the status reader reports no signal and the
 * reconciler is a safe no-op until O-11 calibrates it. Each leg runs in its own try/catch so one
 * failing leg (gateway hiccup, lock) never stalls the batch, and the read-then-CAS reconciler makes
 * the next run a safe retry.
 */
class RefundReconScan
{
    /**
     * @param RefundLegLoader $loader
     * @param RefundReconciler $reconciler
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RefundLegLoader $loader,
        private readonly RefundReconciler $reconciler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->loader->loadPollable() as $leg) {
            try {
                $this->reconciler->reconcile($leg);
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: refund recon error for leg %d (order %d, will retry) — %s',
                        (int) $leg->getId(),
                        $leg->getOrderId(),
                        $t->getMessage()
                    ),
                    ['exception' => $t]
                );
            }
        }
    }
}
