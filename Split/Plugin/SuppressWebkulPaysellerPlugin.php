<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Plugin;

use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Controller\Adminhtml\Order\Payseller;

/**
 * A5c-4b — INV-17 suppressor for Webkul's single admin "Pay Seller" controller. When PayU's
 * split-settlement manages a vendor's payout for the order, this short-circuits the controller with
 * a redirect so the native manual payout never runs — defence-in-depth before the A5c-4a mirror
 * flips the saleslist rows paid_status=1.
 *
 * A `before` plugin cannot stop a controller, so this is an `around` returning a Redirect Result
 * without calling proceed(). All the decision + fail-closed logic lives in WebkulPayoutSuppressor;
 * this plugin only extracts the controller's single saleslist id (autoorderid) and delegates.
 */
class SuppressWebkulPaysellerPlugin
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
     * @param Payseller $subject
     * @param \Closure $proceed
     * @return \Magento\Framework\Controller\ResultInterface|mixed
     */
    public function aroundExecute(Payseller $subject, \Closure $proceed)
    {
        $request = $subject->getRequest();
        $sellerId = (int) $request->getParam('seller_id');
        $autoOrderId = (int) $request->getParam('autoorderid');

        $suppression = $this->suppressor->controllerSuppression($sellerId, [$autoOrderId]);

        return $suppression ?? $proceed();
    }
}
