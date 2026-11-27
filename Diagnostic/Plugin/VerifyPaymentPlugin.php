<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Diagnostic\Plugin;

use RKD\PayuSplit\Model\Config;
use Magento\Sales\Model\Order;
use PayUIndia\Payu\Model\Payu;
use Psr\Log\LoggerInterface;

/**
 * Hook 4 — wraps PayUIndia\Payu\Model\Payu::verifyPayment, the cURL roundtrip
 * to PayU's verify_payment endpoint. Captures result + duration; on cURL
 * failure (RuntimeException, transport errors), logs and re-throws.
 *
 * Note: verifyPayment runs only when payment/payu/verifypayment is enabled.
 * If config is off, upstream returns true unconditionally — this plugin still
 * fires (it wraps the method, not the config branch) and logs result=true.
 */
class VerifyPaymentPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Payu $subject
     * @param \Closure $proceed
     * @param Order $order
     * @param string $txnid
     * @return bool
     */
    public function aroundVerifyPayment(Payu $subject, \Closure $proceed, Order $order, $txnid): bool
    {
        if (!$this->config->isLoggingEnabled()) {
            return (bool)$proceed($order, $txnid);
        }

        $start = microtime(true);
        $context = [
            'order_increment_id' => (string)$order->getIncrementId(),
            'txnid' => (string)$txnid,
        ];

        try {
            $result = (bool)$proceed($order, $txnid);
            $this->logger->info('verifyPayment completed', $context + [
                'result' => $result,
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
            ]);
            return $result;
        } catch (\Throwable $t) {
            $this->logger->error('verifyPayment threw', $context + [
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
                'exception_class' => get_class($t),
                'exception_message' => $t->getMessage(),
            ]);
            throw $t;
        }
    }
}
