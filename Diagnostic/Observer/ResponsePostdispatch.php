<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Diagnostic\Observer;

use RKD\PayuSplit\Model\Config;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Hook 5 — fires on controller_action_postdispatch_payu_standard_response.
 *
 * If Hook 1 (predispatch) logs a "controller entry" line but this hook never logs
 * a paired "controller exit" line for the same request, that's our signal of the
 * upstream die-trap at Response.php:137 (echo + die() inside catch). The absence
 * of an exit log IS the diagnostic.
 */
class ResponsePostdispatch implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly HttpResponse $response
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isLoggingEnabled()) {
            return;
        }

        $location = $this->response->getHeader('Location');
        $redirectTo = $location ? $location->getFieldValue() : null;

        $this->logger->info('payu response controller exit', [
            'http_status' => $this->response->getHttpResponseCode(),
            'redirect_to' => $redirectTo,
        ]);
    }
}
