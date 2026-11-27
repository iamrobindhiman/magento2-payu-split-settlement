<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Gateway;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Mints and caches PayU onboarding bearer tokens.
 *
 * Two things about this are not obvious and both were measured, not read:
 *
 * 1. TOKENS ARE PER-SCOPE, and a token minted for one scope returns 401 on the
 *    other's routes. So the cache is keyed by scope; there is no single "the token".
 *    (PayU documents the scopes but never says they are not interchangeable. We
 *    found out by getting a 401 on the listing with a perfectly valid token.)
 *
 * 2. THE TTL IS 7199 SECONDS — two hours. docs/payu/kb/onboarding-api.md records it
 *    as ~30 days, which is wrong by a factor of about 360. Anything built on the
 *    documented figure would have carried a dead token for a month.
 *
 * The cached entry stores its own expiry alongside the token rather than trusting the
 * cache backend's lifetime alone, so a backend that serves a stale entry cannot hand
 * back an expired bearer.
 *
 * Secrets discipline (N-5): the client_id, the client_secret and the token itself are
 * never logged, never placed in an exception message, and never returned in an error.
 */
class PayuOnboardingTokenProvider
{
    private const CACHE_PREFIX = 'rkd_payu_onboarding_token_';
    private const CACHE_TAG = 'RKD_PAYU_ONBOARDING_TOKEN';
    private const METHOD_POST = 'POST';

    private const HTTP_TIMEOUT_SECONDS = 30;

    /**
     * Retire a token this many seconds before PayU would. A request that leaves here
     * with 3 seconds of life can still arrive expired; two minutes cannot.
     */
    private const EXPIRY_MARGIN_SECONDS = 120;

    /** Used only if PayU omits expires_in, which it never has. */
    private const FALLBACK_TTL_SECONDS = 1800;

    private const HTTP_SERVER_ERROR_THRESHOLD = 500;

    /**
     * @param OnboardingConfig $config
     * @param HttpClientFactory $httpClientFactory
     * @param CacheInterface $cache
     * @param Json $json
     * @param Logger $logger
     */
    public function __construct(
        private readonly OnboardingConfig $config,
        private readonly HttpClientFactory $httpClientFactory,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly Logger $logger
    ) {
    }

    /**
     * A live bearer token for the given scope, from cache when one is still good.
     *
     * @param string $scope One of OnboardingConfig::SCOPE_*
     * @return string
     * @throws PayuOnboardingRequestException When PayU will not issue a token
     */
    public function getToken(string $scope): string
    {
        $cached = $this->readCache($scope);
        if ($cached !== null) {
            return $cached;
        }
        return $this->mint($scope);
    }

    /**
     * Drop the cached token for a scope. Called when PayU answers 401 on a request we
     * believed was authenticated, so the retry mints a fresh one (T-10).
     *
     * @param string $scope
     * @return void
     */
    public function forget(string $scope): void
    {
        $this->cache->remove($this->cacheKey($scope));
    }

    /**
     * @param string $scope
     * @return string|null
     */
    private function readCache(string $scope): ?string
    {
        $raw = $this->cache->load($this->cacheKey($scope));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $entry = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        if (!is_array($entry) || !isset($entry['token'], $entry['expires_at'])) {
            return null;
        }
        if ((int)$entry['expires_at'] <= time()) {
            return null;
        }

        return (string)$entry['token'];
    }

    /**
     * @param string $scope
     * @return string
     * @throws PayuOnboardingRequestException
     */
    private function mint(string $scope): string
    {
        if (!$this->config->isConfigured()) {
            throw new PayuOnboardingRequestException(
                'PayU onboarding credentials are not configured — cannot request a token.'
            );
        }

        $endpoint = $this->config->getTokenEndpoint();

        $client = $this->httpClientFactory->create();
        $client->setUri($endpoint);
        $client->setMethod(self::METHOD_POST);
        $client->setOptions(['timeout' => self::HTTP_TIMEOUT_SECONDS]);
        $client->setParameterPost([
            'client_id' => $this->config->getClientId(),
            'client_secret' => $this->config->getClientSecret(),
            'grant_type' => 'client_credentials',
            'scope' => $scope,
        ]);

        try {
            $response = $client->send();
        } catch (\Throwable $t) {
            // The exception text can carry the POST body on some adapters. Never re-throw it.
            $this->logger->error('PayU onboarding token transport failure', [
                'scope' => $scope,
                'endpoint' => $endpoint,
            ]);
            throw new PayuOnboardingRequestException(
                sprintf('PayU token request for scope "%s" failed at transport level.', $scope)
            );
        }

        $status = (int)$response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status >= self::HTTP_SERVER_ERROR_THRESHOLD) {
            throw new PayuOnboardingRequestException(
                sprintf('PayU token endpoint returned HTTP %d — retryable.', $status)
            );
        }

        $decoded = $this->decode($body);
        $token = isset($decoded['access_token']) && is_scalar($decoded['access_token'])
            ? (string)$decoded['access_token']
            : '';

        if ($token === '') {
            // Body may echo the client_id back in an error; log the status only.
            $this->logger->error('PayU onboarding token refused', [
                'scope' => $scope,
                'http_status' => $status,
            ]);
            throw new PayuOnboardingRequestException(
                sprintf('PayU issued no access_token for scope "%s" (HTTP %d).', $scope, $status)
            );
        }

        $ttl = isset($decoded['expires_in']) && is_numeric($decoded['expires_in'])
            ? (int)$decoded['expires_in']
            : self::FALLBACK_TTL_SECONDS;

        $this->store($scope, $token, $ttl);

        $this->logger->info('PayU onboarding token issued', [
            'scope' => $scope,
            'expires_in' => $ttl,
        ]);

        return $token;
    }

    /**
     * @param string $scope
     * @param string $token
     * @param int $ttlSeconds
     * @return void
     */
    private function store(string $scope, string $token, int $ttlSeconds): void
    {
        $lifetime = $ttlSeconds - self::EXPIRY_MARGIN_SECONDS;
        if ($lifetime <= 0) {
            // Too short to be worth caching; the next call mints again.
            return;
        }

        $this->cache->save(
            $this->json->serialize(['token' => $token, 'expires_at' => time() + $lifetime]),
            $this->cacheKey($scope),
            [self::CACHE_TAG],
            $lifetime
        );
    }

    /**
     * Keyed by environment, scope AND a digest of the client id, so rotating the
     * credential or switching environment can never serve a token minted for the old one.
     *
     * @param string $scope
     * @return string
     */
    private function cacheKey(string $scope): string
    {
        return self::CACHE_PREFIX . hash(
            'sha256',
            $this->config->getEnvironment() . '|' . $scope . '|' . $this->config->getClientId()
        );
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
