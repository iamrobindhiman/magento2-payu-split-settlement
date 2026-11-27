<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Diagnostic\Observer;

use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use RKD\PayuSplit\Model\Config;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Hook 1 — fires on controller_action_predispatch_payu_standard_response.
 * Captures the full response payload from PayU before any controller logic runs.
 * Bolt mode wraps the entire response in a `full_response` JSON string; we decode
 * it so log entries show real keys, not `{"full_response": "..."}`.
 */
class ResponsePredispatch implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly PayloadRedactor $redactor,
        private readonly LoggerInterface $logger,
        private readonly HttpRequest $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isLoggingEnabled()) {
            return;
        }

        $rawParams = $this->request->getParams();
        $boltMode = isset($rawParams['full_response']);

        if ($boltMode) {
            $decoded = json_decode((string)$rawParams['full_response'], true);
            $params = is_array($decoded) ? $decoded : ['_decode_error' => 'json_decode failed'];
        } else {
            $params = $rawParams;
        }

        $this->logger->info('payu response controller entry', [
            'payload' => $this->redactor->redact($params),
            'method' => $this->request->getMethod(),
            'client_ip' => $this->request->getClientIp(),
            'user_agent' => (string)$this->request->getServer('HTTP_USER_AGENT', ''),
            'bolt_mode' => $boltMode,
        ]);
    }
}
