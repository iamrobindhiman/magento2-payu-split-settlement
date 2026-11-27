<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\ReleaseCandidateLoader;
use RKD\PayuSplit\Split\Service\ReleaseProcessor;
use Psr\Log\LoggerInterface;

/**
 * The release executor scan (A5c-3): for each coarse-eligible segment — vendor (delivery + window
 * cleared) or parent commission (parent-last) — it hands the segment to the ReleaseProcessor,
 * which re-checks the precise gate under a row lock and fires release_settlement exactly once
 * (ELIGIBLE → RELEASE_REQUESTED). It moves NO money itself; the processor is the money mover and
 * its CAS claim is the single-fire guarantee. The A5c-5 recon then promotes RELEASE_REQUESTED →
 * RELEASED on the settled-amount readback.
 *
 * Gated by the SAME master kill-switch as the recon (rkd_payu/split/release_enabled, default OFF):
 * release is PROD-VERIFY only (E-07 → O-11), so while OFF this scan is an explicit no-op. Each
 * segment runs in its own try/catch — a gateway hiccup on one never stalls the batch, and the
 * processor's write-ahead CAS + idempotency make the next run a safe retry.
 */
class ReleaseExecutorScan
{
    /**
     * @param SplitConfig $config
     * @param ReleaseCandidateLoader $loader
     * @param ReleaseProcessor $processor
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SplitConfig $config,
        private readonly ReleaseCandidateLoader $loader,
        private readonly ReleaseProcessor $processor,
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

        foreach ($this->loader->loadCandidates() as $segment) {
            try {
                $this->processor->release($segment);
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: release executor error for segment %d (order %d, will retry) — %s',
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
