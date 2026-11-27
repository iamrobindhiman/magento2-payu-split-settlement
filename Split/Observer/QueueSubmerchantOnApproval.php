<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Service\OnboardingQueue;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * mp_approve_seller -> queue the vendor for PayU onboarding (S-3, T-01).
 *
 * Writes one row and makes no PayU call (N-2). It runs inside Webkul's MassApprove loop,
 * which has no per-item try/catch, so it must never throw: a failure here would abort the
 * approval of every vendor after this one in the batch (N-1). Anything that goes wrong is
 * logged, and the vendor can be queued later from the CLI.
 *
 * With the feature switched off it does nothing at all (N-6, T-19).
 */
class QueueSubmerchantOnApproval implements ObserverInterface
{
    /**
     * @param OnboardingConfig $config
     * @param OnboardingQueue $queue
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OnboardingConfig $config,
        private readonly OnboardingQueue $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $seller = $observer->getEvent()->getData('seller');
        $sellerId = $seller instanceof DataObject ? (int)$seller->getId() : 0;
        if ($sellerId <= 0) {
            $this->logger->warning('PayU onboarding: mp_approve_seller fired without a seller id');
            return;
        }

        try {
            $result = $this->queue->enqueue($sellerId);
            $this->logger->info('PayU onboarding: approval queued', [
                'seller_id' => $sellerId,
                'result' => $result,
            ]);
        } catch (\Throwable $t) {
            $this->logger->error('PayU onboarding: could not queue an approved vendor', [
                'seller_id' => $sellerId,
                'exception' => get_class($t),
                'error' => $t->getMessage(),
            ]);
        }
    }
}
