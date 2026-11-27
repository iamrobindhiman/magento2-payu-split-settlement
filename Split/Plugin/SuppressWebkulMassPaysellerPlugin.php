<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Plugin;

use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Controller\Adminhtml\Order\MassPayseller;

/**
 * A5c-4b — INV-17 suppressor for Webkul's MASS "Pay Seller" controller. Splits the submitted
 * wksellerorderids CSV into saleslist ids and delegates the decision to WebkulPayoutSuppressor,
 * which blocks the whole batch (all-or-nothing) if ANY row is PayU-managed — letting a mass payout
 * proceed with a managed row filtered out would corrupt Webkul's summed payout math, so the admin
 * is told to deselect the managed order instead.
 *
 * A `before` plugin cannot stop a controller, so this is an `around` returning the suppressor's
 * Redirect Result without calling proceed() when blocked.
 */
class SuppressWebkulMassPaysellerPlugin
{
    /**
     * @param WebkulPayoutSuppressor $suppressor
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly WebkulPayoutSuppressor $suppressor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param MassPayseller $subject
     * @param \Closure $proceed
     * @return \Magento\Framework\Controller\ResultInterface|mixed
     */
    public function aroundExecute(MassPayseller $subject, \Closure $proceed)
    {
        $request = $subject->getRequest();
        $sellerId = (int) $request->getParam('seller_id');
        $csv = (string) $request->getParam('wksellerorderids');
        $saleslistIds = $csv === '' ? [] : explode(',', $csv);

        $suppression = $this->suppressor->controllerSuppression($sellerId, $saleslistIds);

        return $suppression ?? $proceed();
    }
}
