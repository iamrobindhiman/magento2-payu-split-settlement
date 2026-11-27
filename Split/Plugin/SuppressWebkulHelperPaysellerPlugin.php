<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Plugin;

use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Helper\Orders;

/**
 * A5c-4b — INV-17 suppressor for Webkul's dormant Helper\Orders::paysellerpayment. The method has
 * ZERO callers today (a hook for an uninstalled online-disbursement add-on), but Webkul has no
 * shared payout choke-point, so we plug it too as defence-in-depth: should that add-on ever activate
 * it, a PayU-managed vendor still cannot be paid twice.
 *
 * The helper returns void and receives the (order, seller) key directly — no saleslist resolution
 * needed — so this around-plugin simply skips proceed() (performing no payout) when the suppressor
 * reports the vendor is PayU-managed (or cannot be verified, fail-closed).
 */
class SuppressWebkulHelperPaysellerPlugin
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
     * @param Orders $subject
     * @param \Closure $proceed
     * @param mixed $order Magento sales order (untyped to match the helper's own signature)
     * @param mixed $sellerid
     * @param mixed $trid
     * @return mixed
     */
    public function aroundPaysellerpayment(Orders $subject, \Closure $proceed, $order, $sellerid, $trid)
    {
        $orderId = (int) $order->getId();
        $sellerId = (int) $sellerid;

        if ($this->suppressor->isManagedPayout($orderId, $sellerId)) {
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: suppressed the dormant Webkul Helper::paysellerpayment for order %d / '
                . 'seller %d — PayU split-settlement manages this payout (INV-17 anti-double-pay).',
                $orderId,
                $sellerId
            ));
            return null;
        }

        return $proceed($order, $sellerid, $trid);
    }
}
