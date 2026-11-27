<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Gateway;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Model\Config as DiagnosticConfig;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\PayuResponseParser;
use RKD\PayuSplit\Split\Service\SplitHashBuilder;
use RKD\PayuSplit\Split\Service\SplitPayloadBuilder;
use Magento\Framework\HTTP\Client\Curl;

/**
 * The PayU split gateway — the only place the module makes a split-related network
 * call. Composes the pure pieces (payload + hash + response parser) with HTTP and
 * the configured credentials/endpoints. Returns a normalised PayuApiResponse for
 * business outcomes (success / PENDING / AGG-* errors) and throws
 * PayuSplitRequestException only for transport failures (so the caller can retry).
 *
 * Secrets discipline: the merchant key and salt are never logged; only the command,
 * endpoint, and var1 (amounts + child keys — not secret) are.
 */
class PayuSplitClient
{
    private const HTTP_TIMEOUT_SECONDS = 30;
    private const HTTP_SERVER_ERROR_THRESHOLD = 500;

    /**
     * @param SplitConfig $config
     * @param SplitHashBuilder $hashBuilder
     * @param SplitPayloadBuilder $payloadBuilder
     * @param PayuResponseParser $responseParser
     * @param Curl $curl
     * @param Logger $logger
     * @param DiagnosticConfig $diagnosticConfig
     */
    public function __construct(
        private readonly SplitConfig $config,
        private readonly SplitHashBuilder $hashBuilder,
        private readonly SplitPayloadBuilder $payloadBuilder,
        private readonly PayuResponseParser $responseParser,
        private readonly Curl $curl,
        private readonly Logger $logger,
        private readonly DiagnosticConfig $diagnosticConfig
    ) {
    }

    /**
     * Split-after: distribute a captured transaction across vendor child merchants
     * and the parent (commission) segment. api_version 7 is mandatory.
     *
     * @param OrderSplitPlan $plan
     * @param string $payuId PayU transaction id (mihpayid) of the captured payment
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    public function paymentSplit(OrderSplitPlan $plan, string $payuId): PayuApiResponse
    {
        $var1 = $this->payloadBuilder->build($plan, $payuId, $this->config->getMerchantKey());
        return $this->post(SplitConfig::COMMAND_PAYMENT_SPLIT, $var1, ['api_version' => '7']);
    }

    /**
     * Read the current split state for a transaction. The idempotency / PENDING-poll
     * source — var1 is the payuId itself.
     *
     * @param string $payuId
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    public function getSplitInfo(string $payuId): PayuApiResponse
    {
        return $this->post(SplitConfig::COMMAND_GET_SPLIT_INFO, $payuId, []);
    }

    /**
     * Release held settlement to a child merchant. var2 (child MID) is NOT part of the hash.
     *
     * @param string $payuId
     * @param string $childMid Numeric child merchant MID
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    public function releaseSettlement(string $payuId, string $childMid): PayuApiResponse
    {
        return $this->post(SplitConfig::COMMAND_RELEASE_SETTLEMENT, $payuId, ['var2' => $childMid]);
    }

    /**
     * Refund a customer against an already-split transaction (split-aware var8 refund).
     * One child-keyed var8 produces two refund legs — child + parent commission (E-12).
     * The response is keyed per merchant key; read it via the RefundResponseParser on
     * PayuApiResponse::raw(), not the flat scalar fields. var2/var3/var8 are NOT hashed
     * (the postservice hash is key|command|var1|salt only).
     *
     * @param string $payuId mihpayid of the already-split transaction (var1)
     * @param string $token Unique merchant refund token, max 23 chars (var2) — the idempotency anchor
     * @param string $totalRupees Total refund amount as a 2-decimal rupee string (var3)
     * @param string $var8Json Per-child allocation JSON: {childKey:{amount,aggregatorRefundAmount}} in rupees
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    public function cancelRefundTransaction(
        string $payuId,
        string $token,
        string $totalRupees,
        string $var8Json
    ): PayuApiResponse {
        return $this->post(
            SplitConfig::COMMAND_CANCEL_REFUND_TRANSACTION,
            $payuId,
            ['var2' => $token, 'var3' => $totalRupees, 'var8' => $var8Json]
        );
    }

    /**
     * Poll a refund leg's processing status (var1 = the request_id PayU returned for that
     * leg). The QUEUED → SUCCESS / FAILURE promotion source. Note: UAT exposes no refund
     * lifecycle (E-12), so this returns a generic error in sandbox and is prod-useful only.
     *
     * @param string $requestId PayU refund request id of the leg to poll
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    public function checkActionStatus(string $requestId): PayuApiResponse
    {
        return $this->post(SplitConfig::COMMAND_CHECK_ACTION_STATUS, $requestId, []);
    }

    /**
     * List the aggregator's transactions over a date window (the crash-recovery
     * reconciliation source, E-10). It is NOT keyed by mihpayid — the caller filters the
     * returned rows by payuId to find a refund that may have landed before a crash. var2/3/4
     * (end datetime, page, page size) are not part of the hash.
     *
     * @param string $fromDateTime Window start, "YYYY-MM-DD HH:MM"
     * @param string $toDateTime Window end, "YYYY-MM-DD HH:MM"
     * @param int $page Page number (1-based)
     * @param int $recordsPerPage Records per page
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    public function getAggregatorTransactions(
        string $fromDateTime,
        string $toDateTime,
        int $page,
        int $recordsPerPage
    ): PayuApiResponse {
        return $this->post(
            SplitConfig::COMMAND_GET_AGGREGATOR_TRANSACTIONS,
            $fromDateTime,
            ['var2' => $toDateTime, 'var3' => (string)$page, 'var4' => (string)$recordsPerPage]
        );
    }

    /**
     * @param string $command
     * @param string $var1 The hashed var1 value (split JSON for payment_split, payuId otherwise)
     * @param array<string, string> $extra Extra POST fields (api_version, var2, …)
     * @return PayuApiResponse
     * @throws PayuSplitRequestException On transport failure
     */
    private function post(string $command, string $var1, array $extra): PayuApiResponse
    {
        $merchantKey = $this->config->getMerchantKey();
        $endpoint = $this->config->getEndpoint($command);

        $fields = [
            'key' => $merchantKey,
            'command' => $command,
            'var1' => $var1,
            'hash' => $this->hashBuilder->postservice($merchantKey, $command, $var1, $this->config->getSalt()),
        ] + $extra;

        $this->logRequest($command, $endpoint, $var1);

        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, self::HTTP_TIMEOUT_SECONDS);
            $this->curl->post($endpoint, $fields);
            $httpStatus = (int)$this->curl->getStatus();
            $rawBody = (string)$this->curl->getBody();
        } catch (\Throwable $t) {
            $this->logger->error(
                'PayU split transport failure: ' . $t->getMessage(),
                ['command' => $command, 'endpoint' => $endpoint]
            );
            throw new PayuSplitRequestException(
                sprintf('PayU "%s" request failed at transport level: %s', $command, $t->getMessage()),
                0,
                $t
            );
        }

        // A 5xx is PayU's server failing, not a business outcome — surface it as a retryable
        // transport failure so the caller retries, rather than treating the HTML error page as
        // an ambiguous (status-less) response that reads as neither success nor failure.
        if ($httpStatus >= self::HTTP_SERVER_ERROR_THRESHOLD) {
            $this->logger->error(
                sprintf('PayU split HTTP %d for "%s"', $httpStatus, $command),
                ['command' => $command, 'endpoint' => $endpoint]
            );
            throw new PayuSplitRequestException(
                sprintf('PayU "%s" returned HTTP %d (server error) — retryable transport failure.', $command, $httpStatus)
            );
        }

        $response = $this->responseParser->parse($rawBody);
        $this->logResponse($command, $response);

        return $response;
    }

    /**
     * @param string $command
     * @param string $endpoint
     * @param string $var1
     * @return void
     */
    private function logRequest(string $command, string $endpoint, string $var1): void
    {
        if (!$this->diagnosticConfig->isLoggingEnabled()) {
            return;
        }
        // var1 carries amounts + child keys (not secret); key/salt/hash are never logged.
        $this->logger->info('PayU split request', [
            'command' => $command,
            'endpoint' => $endpoint,
            'var1' => $var1,
        ]);
    }

    /**
     * @param string $command
     * @param PayuApiResponse $response
     * @return void
     */
    private function logResponse(string $command, PayuApiResponse $response): void
    {
        if (!$this->diagnosticConfig->isLoggingEnabled()) {
            return;
        }
        $this->logger->info('PayU split response', [
            'command' => $command,
            'status' => $response->status(),
            'format' => $response->format(),
            'error_code' => $response->errorCode(),
            'message' => $response->message(),
        ]);
    }
}
