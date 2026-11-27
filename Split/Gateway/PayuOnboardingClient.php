<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Gateway;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Exception\PayuOnboardingNotSentException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Service\OnboardingErrorText;
use RKD\PayuSplit\Split\Service\OnboardingPayloadBuilder;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The only place the module makes a PayU ONBOARDING network call.
 *
 * Sibling of PayuSplitClient, which owns the transaction postservice. They share no
 * credential, no host and no wire format, which is why they are separate classes:
 * onboarding is OAuth2 + REST/JSON, split is merchant_key + hashed form posts.
 *
 * Scope routing is not incidental. A token minted for refer_child_merchant answers
 * 401 on the listing, and a fetch_child_merchants token answers 401 on everything
 * else. Each method below therefore names its own scope, measured on UAT 2026-09-10:
 *
 *   POST   /product_accounts                    refer_child_merchant
 *   PUT    /product_accounts/{uuid}             refer_child_merchant
 *   GET    /product_accounts/{uuid}             refer_child_merchant
 *   GET    /product_accounts/{mid}/sub_accounts fetch_child_merchants
 *
 * Note the third line. A previous round recorded "GET by uuid is 401, use the listing
 * instead" and designed around it; that was a scope mismatch, not a permission wall.
 * The single-child read works, and it is the only route that returns KYC, bank and
 * agreement status — the listing carries none of them.
 *
 * Business refusals (a 422) are RETURNED, not thrown, because they are answers:
 * "Bank details already submitted" tells the caller the work is already done.
 * Only a transport failure or a 5xx throws, so the cron leaves state alone and retries.
 *
 * Secrets discipline (N-5): request bodies are never logged. They carry PAN and, on
 * the bank call, the vendor's settlement account number.
 */
class PayuOnboardingClient
{
    /**
     * Plain verbs rather than Laminas constants: three universal strings do not need
     * a framework dependency, and the gateway reads better without one.
     */
    private const METHOD_GET = 'GET';
    private const METHOD_POST = 'POST';
    private const METHOD_PUT = 'PUT';

    private const HTTP_TIMEOUT_SECONDS = 45;
    private const HTTP_SERVER_ERROR_THRESHOLD = 500;

    /**
     * @param OnboardingConfig $config
     * @param PayuOnboardingTokenProvider $tokenProvider
     * @param OnboardingPayloadBuilder $payloadBuilder
     * @param HttpClientFactory $httpClientFactory
     * @param Json $json
     * @param Logger $logger
     */
    public function __construct(
        private readonly OnboardingConfig $config,
        private readonly PayuOnboardingTokenProvider $tokenProvider,
        private readonly OnboardingPayloadBuilder $payloadBuilder,
        private readonly HttpClientFactory $httpClientFactory,
        private readonly Json $json,
        private readonly Logger $logger
    ) {
    }

    /**
     * Create a child merchant under our aggregator.
     *
     * The payload builder refuses to produce a body without aggregator_parent_mid,
     * so this cannot create an orphan (T-04).
     *
     * @param ChildMerchantDraft $draft
     * @return OnboardingApiResponse
     * @throws PayuOnboardingRequestException On transport failure
     * @throws \RKD\PayuSplit\Split\Exception\OnboardingPayloadException When the payload is unsafe
     */
    public function createChildMerchant(ChildMerchantDraft $draft): OnboardingApiResponse
    {
        return $this->request(
            self::METHOD_POST,
            $this->config->getApiEndpoint(),
            OnboardingConfig::SCOPE_REFER,
            $this->payloadBuilder->buildCreate($draft),
            'create-child-merchant'
        );
    }

    /**
     * Attach the vendor's settlement bank account.
     *
     * ONE SHOT. PayU accepts the bank detail once; a second call answers 422 "Bank
     * details already submitted" and decrements bank_update_attempt_left. The caller
     * must not reach here twice for the same vendor — the state machine is the guard,
     * and ChildMerchant::hasBankAttached() is the way to ask PayU rather than trust it.
     *
     * @param string $uuid PayU account uuid from the create response
     * @param string $accountNumber Decrypted moments before the call, never stored in plaintext
     * @param string $ifsc
     * @param string $holderName
     * @return OnboardingApiResponse
     * @throws PayuOnboardingRequestException On transport failure
     * @throws \RKD\PayuSplit\Split\Exception\OnboardingPayloadException When a value would waste the attempt
     */
    public function attachBankDetail(
        string $uuid,
        string $accountNumber,
        string $ifsc,
        string $holderName
    ): OnboardingApiResponse {
        return $this->request(
            self::METHOD_PUT,
            $this->config->getChildEndpoint($uuid),
            OnboardingConfig::SCOPE_REFER,
            $this->payloadBuilder->buildBankDetail($accountNumber, $ifsc, $holderName),
            'attach-bank-detail'
        );
    }

    /**
     * Read one child merchant back in full — the activation-polling source (R-5).
     *
     * @param string $uuid
     * @return OnboardingApiResponse
     * @throws PayuOnboardingRequestException On transport failure
     */
    public function fetchChildMerchant(string $uuid): OnboardingApiResponse
    {
        return $this->request(
            self::METHOD_GET,
            $this->config->getChildEndpoint($uuid),
            OnboardingConfig::SCOPE_REFER,
            null,
            'fetch-child-merchant'
        );
    }

    /**
     * Every child under our parent MID. Thin rows (uuid, mid, pg_key, name, email) —
     * the reconciliation and backfill source, not a status source.
     *
     * @return OnboardingApiResponse
     * @throws PayuOnboardingRequestException On transport failure
     */
    public function listSubAccounts(): OnboardingApiResponse
    {
        return $this->request(
            self::METHOD_GET,
            $this->config->getSubAccountsEndpoint(),
            OnboardingConfig::SCOPE_FETCH,
            null,
            'list-sub-accounts'
        );
    }

    /**
     * @param string $method
     * @param string $url
     * @param string $scope
     * @param array<string, mixed>|null $payload
     * @param string $operation Label for logging; never contains vendor data
     * @return OnboardingApiResponse
     * @throws PayuOnboardingRequestException
     */
    private function request(
        string $method,
        string $url,
        string $scope,
        ?array $payload,
        string $operation
    ): OnboardingApiResponse {
        $response = $this->send($method, $url, $scope, $payload, $operation);

        // A token that expired mid-run is indistinguishable from a bad one until we
        // retry with a fresh one (T-10). Exactly one retry: a second 401 is a real
        // authorisation problem — most likely the wrong scope for this route — and
        // looping on it would burn the token endpoint.
        if ($response->isUnauthorized()) {
            $this->logger->info('PayU onboarding 401 — refreshing token and retrying once', [
                'operation' => $operation,
                'scope' => $scope,
            ]);
            $this->tokenProvider->forget($scope);
            $response = $this->send($method, $url, $scope, $payload, $operation);
        }

        return $response;
    }

    /**
     * @param string $method
     * @param string $url
     * @param string $scope
     * @param array<string, mixed>|null $payload
     * @param string $operation
     * @return OnboardingApiResponse
     * @throws PayuOnboardingRequestException
     */
    private function send(
        string $method,
        string $url,
        string $scope,
        ?array $payload,
        string $operation
    ): OnboardingApiResponse {
        try {
            $token = $this->tokenProvider->getToken($scope);
        } catch (PayuOnboardingNotSentException $e) {
            throw $e;
        } catch (PayuOnboardingRequestException $e) {
            // No token, so nothing was sent — say so, so a create can tell it apart from a lost answer.
            throw new PayuOnboardingNotSentException($e->getMessage());
        }

        $client = $this->httpClientFactory->create();
        $client->setUri($url);
        $client->setMethod($method);
        $client->setOptions(['timeout' => self::HTTP_TIMEOUT_SECONDS]);

        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $client->setRawBody($this->json->serialize($payload));
        }
        $client->setHeaders($headers);

        $this->logger->info('PayU onboarding request', [
            'operation' => $operation,
            'method' => $method,
            'scope' => $scope,
        ]);

        try {
            $httpResponse = $client->send();
        } catch (\Throwable $t) {
            // The message can echo the request body, which carries PAN and bank data.
            $this->logger->error('PayU onboarding transport failure', [
                'operation' => $operation,
                'method' => $method,
            ]);
            throw new PayuOnboardingRequestException(
                sprintf('PayU onboarding "%s" failed at transport level.', $operation)
            );
        }

        $status = (int)$httpResponse->getStatusCode();
        $raw = (string)$httpResponse->getBody();

        if ($status >= self::HTTP_SERVER_ERROR_THRESHOLD) {
            $this->logger->error('PayU onboarding server error', [
                'operation' => $operation,
                'http_status' => $status,
            ]);
            throw new PayuOnboardingRequestException(
                sprintf('PayU onboarding "%s" returned HTTP %d (server error) — retryable.', $operation, $status)
            );
        }

        $response = new OnboardingApiResponse($status, $this->decode($raw), $raw);

        $this->logger->info('PayU onboarding response', [
            'operation' => $operation,
            'http_status' => $status,
            // PayU may echo a submitted PAN or account number back in an error (N-5, wave-3 S-F4).
            'errors' => array_map([OnboardingErrorText::class, 'clean'], $response->errorMessages()),
        ]);

        return $response;
    }

    /**
     * @param string $body
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }
}
