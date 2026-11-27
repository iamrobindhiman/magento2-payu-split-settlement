<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Service\SplitOrderManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Records a split plan the moment a PayU order is paid (sales_order_payment_pay).
 *
 * Only PayU-paid orders are split-settled, so non-PayU methods are ignored. The
 * whole body is wrapped in a Throwable guard: recording the plan must NEVER break
 * the payment/order flow — a failure is logged and the order proceeds untouched.
 * (catch (\Throwable) — never the unqualified catch(Exception) the upstream module
 * shipped; see CodingStandardsGuardTest.)
 */
class RecordSplitOnPay implements ObserverInterface
{
    /**
     * @param SplitOrderManager $manager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SplitOrderManager $manager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $payment = $observer->getEvent()->getData('payment');
            if ($payment === null) {
                return;
            }

            // Split settlement applies to PayU orders only.
            if (stripos((string) $payment->getMethod(), 'payu') === false) {
                return;
            }

            $order = $payment->getOrder();
            if ($order === null) {
                return;
            }

            $this->manager->recordPlan($order);
        } catch (\Throwable $t) {
            $this->logger->error(
                'RKD_PayuSplit: RecordSplitOnPay failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }
}
