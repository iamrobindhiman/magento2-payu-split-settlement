<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Diagnostic\Plugin;

use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use RKD\PayuSplit\Model\Config;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use PayUIndia\Payu\Model\Payu;
use Psr\Log\LoggerInterface;

/**
 * Hook 3 — wraps PayUIndia\Payu\Model\Payu::postProcessing.
 *
 * Upstream catch(Exception) at Model/Payu.php:440 is unqualified inside a namespaced
 * file, resolving to PayUIndia\Payu\Model\Exception (doesn't exist), so the catch
 * never matches. Real exceptions in this method propagate up into the controller's
 * die-trap. Our around-plugin catches \Throwable, logs everything, and re-throws
 * so upstream behaviour is unchanged — we just witness what's being silently swallowed
 * (or in this case, silently propagated past the broken catch).
 */
class PostProcessingPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly PayloadRedactor $redactor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Payu $subject
     * @param \Closure $proceed
     * @param Order $order
     * @param DataObject $payment
     * @param array<string, mixed> $response
     * @return mixed
     */
    public function aroundPostProcessing(
        Payu $subject,
        \Closure $proceed,
        Order $order,
        DataObject $payment,
        $response
    ) {
        if (!$this->config->isLoggingEnabled()) {
            return $proceed($order, $payment, $response);
        }

        $responseArr = is_array($response) ? $response : [];
        $context = [
            'order_increment_id' => (string)$order->getIncrementId(),
            'payload' => $this->redactor->redact($responseArr),
            'payload_keys' => array_keys($responseArr),
        ];

        $this->logger->info('postProcessing start', $context);

        $start = microtime(true);
        try {
            $result = $proceed($order, $payment, $response);
            $this->logger->info('postProcessing end', $context + [
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
            ]);
            return $result;
        } catch (\Throwable $t) {
            $this->logger->error('postProcessing threw', $context + [
                'duration_ms' => (int)((microtime(true) - $start) * 1000),
                'exception_class' => get_class($t),
                'exception_message' => $t->getMessage(),
                'exception_file' => $t->getFile(),
                'exception_line' => $t->getLine(),
                'trace' => array_slice(explode("\n", $t->getTraceAsString()), 0, 20),
            ]);
            throw $t;
        }
    }
}
