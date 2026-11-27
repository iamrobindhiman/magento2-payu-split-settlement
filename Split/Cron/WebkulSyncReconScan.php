<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\UnsyncedReleasedLoader;
use RKD\PayuSplit\Split\Service\WebkulSettlementSync;
use Psr\Log\LoggerInterface;

/**
 * A5c-4 — the Webkul-mirror reconcile scan: the self-heal backstop for the rkd_payu_segment_released
 * event. For each RELEASED segment not yet mirrored (webkul_synced_at IS NULL) it re-drives
 * WebkulSettlementSync, so a release whose event was lost (a crash between the RELEASED commit and
 * the listener) still reaches Webkul's ledger. Idempotent (the sync no-ops a segment already
 * synced), per-segment isolated.
 *
 * Gated by the same release kill-switch as the rest of the release path
 * (rkd_payu/split/release_enabled, default OFF) — no segment reaches RELEASED until O-11, so this
 * scan is an explicit no-op until then.
 */
class WebkulSyncReconScan
{
    /**
     * @param SplitConfig $config
     * @param UnsyncedReleasedLoader $loader
     * @param WebkulSettlementSync $sync
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SplitConfig $config,
        private readonly UnsyncedReleasedLoader $loader,
        private readonly WebkulSettlementSync $sync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isReleaseEnabled()) {
            return;
        }

        foreach ($this->loader->loadUnsynced() as $segment) {
            try {
                $this->sync->sync($segment);
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: Webkul mirror reconcile error for segment %d (order %d, will retry) — %s',
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
