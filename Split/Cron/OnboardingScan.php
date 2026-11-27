<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Service\OnboardingClaim;
use RKD\PayuSplit\Split\Service\OnboardingProcessor;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * S-3 — the onboarding scan. Every approved vendor with work to do is taken as far as it
 * can go: created at PayU, bank attached, handed to staff for the Schedule C click.
 *
 * Each vendor is isolated: the processor turns every failure into a stored state, and this
 * loop catches anything that escapes anyway, so one bad vendor never stops the batch.
 * Switched off, or half-configured, it does nothing (N-6).
 */
class OnboardingScan
{
    /** A few vendors a run is plenty; each makes up to three PayU calls of up to 45 s. */
    private const BATCH_SIZE = 20;

    /**
     * @param OnboardingConfig $config
     * @param OnboardingClaim $claim
     * @param OnboardingProcessor $processor
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OnboardingConfig $config,
        private readonly OnboardingClaim $claim,
        private readonly OnboardingProcessor $processor,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, string> seller id => outcome, for the CLI to print
     */
    public function execute(): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }
        if (!$this->config->isConfigured()) {
            $this->logger->warning(
                'PayU onboarding is enabled but not configured (client id, secret or parent MID missing).'
            );
            return [];
        }

        if ($this->processor->isPaused()) {
            $this->logger->warning('PayU onboarding scan skipped: paused after PayU rejected our credentials.');
            return [];
        }

        $outcomes = [];
        foreach ($this->claim->dueSellerIds($this->dateTime->gmtDate('Y-m-d H:i:s'), self::BATCH_SIZE) as $sellerId) {
            try {
                $outcomes[$sellerId] = $this->processor->process($sellerId);
                if ($outcomes[$sellerId] === OnboardingProcessor::OUTCOME_PAUSED) {
                    // Wrong credentials fail every vendor the same way — stop here.
                    break;
                }
            } catch (\Throwable $t) {
                $outcomes[$sellerId] = 'error';
                $this->logger->error('PayU onboarding scan: vendor escaped the processor', [
                    'seller_id' => $sellerId,
                    'exception' => get_class($t),
                ]);
            }
        }
        return $outcomes;
    }
}
