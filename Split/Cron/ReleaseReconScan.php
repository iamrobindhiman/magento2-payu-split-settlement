<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\ReleaseReconciler;
use RKD\PayuSplit\Split\Service\ReleaseRequestedLoader;
use Psr\Log\LoggerInterface;

/**
 * The release readback reconciliation scan (A5c-5). For each segment sitting in
 * RELEASE_REQUESTED — left there by A5c-2's ReleaseProcessor after it fired release_settlement —
 * it hands the segment to the ReleaseReconciler, which reads the settled amount back from PayU
 * and promotes RELEASE_REQUESTED → RELEASED (INV-18) or freezes a mismatch.
 *
 * This scan is the prerequisite that lets the release executor (A5c-3) ever be enabled: without
 * it, segments would strand at RELEASE_REQUESTED forever and parent-last (INV-14) would never
 * fire. Both the executor and this recon are gated by the SAME master kill-switch
 * (rkd_payu/split/release_enabled, default OFF) — release is PROD-VERIFY only (E-07 → O-11), so
 * while the switch is OFF this scan is an explicit no-op.
 *
 * Each segment runs in its own try/catch: a gateway hiccup or transport failure on one segment
 * never stalls the batch, and the recon is read-then-CAS so the next run is a safe retry.
 */
class ReleaseReconScan
{
    /**
     * @param SplitConfig $config
     * @param ReleaseRequestedLoader $loader
     * @param ReleaseReconciler $reconciler
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SplitConfig $config,
        private readonly ReleaseRequestedLoader $loader,
        private readonly ReleaseReconciler $reconciler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isReleaseEnabled()) {
            // Release is launch-gated (E-07 → O-11). Stay inert until explicitly enabled.
            return;
        }

        foreach ($this->loader->loadRequested() as $segment) {
            try {
                $this->reconciler->reconcile($segment);
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: release recon error for segment %d (order %d, will retry) — %s',
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
