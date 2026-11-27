<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Gateway;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Gateway\HttpClientFactory;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingTokenProvider;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use Laminas\Http\Client as LaminasHttpClient;
use Laminas\Http\Response;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * The token provider's two non-obvious jobs, both measured against live UAT:
 * tokens live 7199s (not the ~30 days our own KB recorded), and they are PER-SCOPE —
 * a refer_child_merchant token answers 401 on the listing.
 */
class PayuOnboardingTokenProviderTest extends TestCase
{
    /** @var array<string, string> */
    private array $cacheStore = [];

    /** @var array<string, int|null> */
    private array $cacheLifetimes = [];

    /** @var array<int, array<string, mixed>> */
    private array $sentPosts = [];

    protected function setUp(): void
    {
        $this->cacheStore = [];
        $this->cacheLifetimes = [];
        $this->sentPosts = [];
    }

    /**
     * @param array<int, Response|\Throwable> $responses Queued, one per send()
     * @param string $clientId
     * @return PayuOnboardingTokenProvider
     */
    private function provider(array $responses, string $clientId = 'client-id-aaa'): PayuOnboardingTokenProvider
    {
        $config = $this->createMock(OnboardingConfig::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('getClientId')->willReturn($clientId);
        $config->method('getClientSecret')->willReturn('client-secret-bbb');
        $config->method('getEnvironment')->willReturn('sandbox');
        $config->method('getTokenEndpoint')->willReturn('https://uat-accounts.payu.in/oauth/token');

        $client = $this->createMock(LaminasHttpClient::class);
        $client->method('setUri')->willReturnSelf();
        $client->method('setMethod')->willReturnSelf();
        $client->method('setOptions')->willReturnSelf();
        $client->method('setParameterPost')->willReturnCallback(
            function (array $params) use ($client) {
                $this->sentPosts[] = $params;
                return $client;
            }
        );
        $client->method('send')->willReturnCallback(static function () use (&$responses) {
            $next = array_shift($responses);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        });

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturn($client);

        return new PayuOnboardingTokenProvider(
            $config,
            $factory,
            $this->cache(),
            new Json(),
            $this->createMock(Logger::class)
        );
    }

    /**
     * @return CacheInterface
     */
    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cacheStore[$id] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id, array $tags = [], $lifeTime = null): bool {
                $this->cacheStore[$id] = $data;
                $this->cacheLifetimes[$id] = $lifeTime;
                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(function (string $id): bool {
            unset($this->cacheStore[$id], $this->cacheLifetimes[$id]);
            return true;
        });
        return $cache;
    }

    /**
     * @param int $status
     * @param string $body
     * @return Response
     */
    private function response(int $status, string $body): Response
    {
        $response = new Response();
        $response->setStatusCode($status);
        $response->setContent($body);
        return $response;
    }

    /**
     * @param string $token
     * @param int $expiresIn
     * @param string $scope
     * @return Response
     */
    private function tokenResponse(string $token, int $expiresIn = 7199, string $scope = 'refer_child_merchant'): Response
    {
        return $this->response(200, (string)json_encode([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => $scope,
            'created_at' => time(),
        ]));
    }

    public function testMintsATokenAndSendsTheClientCredentialsGrant(): void
    {
        $provider = $this->provider([$this->tokenResponse('tok-1')]);

        self::assertSame('tok-1', $provider->getToken(OnboardingConfig::SCOPE_REFER));
        self::assertSame('client_credentials', $this->sentPosts[0]['grant_type']);
        self::assertSame(OnboardingConfig::SCOPE_REFER, $this->sentPosts[0]['scope']);
    }

    public function testASecondCallIsServedFromCacheWithoutMintingAgain(): void
    {
        // Only one response is queued; a second send() would shift null and fail.
        $provider = $this->provider([$this->tokenResponse('tok-1')]);

        self::assertSame('tok-1', $provider->getToken(OnboardingConfig::SCOPE_REFER));
        self::assertSame('tok-1', $provider->getToken(OnboardingConfig::SCOPE_REFER));
        self::assertCount(1, $this->sentPosts);
    }

    /**
     * PayU's 7199s minus our two-minute safety margin. A token cached for its full
     * life can be handed to a request that arrives after it expires.
     */
    public function testTheCachedLifetimeStopsShortOfPayusExpiry(): void
    {
        $provider = $this->provider([$this->tokenResponse('tok-1', 7199)]);
        $provider->getToken(OnboardingConfig::SCOPE_REFER);

        self::assertSame([7199 - 120], array_values($this->cacheLifetimes));
    }

    /**
     * The measured failure: the same credential, two scopes, and a token minted for
     * one is rejected on the other's routes. So they cannot share a cache entry.
     */
    public function testEachScopeGetsItsOwnCacheEntryAndItsOwnToken(): void
    {
        $provider = $this->provider([
            $this->tokenResponse('tok-refer', 7199, OnboardingConfig::SCOPE_REFER),
            $this->tokenResponse('tok-fetch', 7199, OnboardingConfig::SCOPE_FETCH),
        ]);

        self::assertSame('tok-refer', $provider->getToken(OnboardingConfig::SCOPE_REFER));
        self::assertSame('tok-fetch', $provider->getToken(OnboardingConfig::SCOPE_FETCH));
        self::assertCount(2, $this->cacheStore, 'The two scopes must not share a cache key');
    }

    public function testAnExpiredCacheEntryIsIgnoredEvenIfTheBackendStillServesIt(): void
    {
        $provider = $this->provider([$this->tokenResponse('tok-fresh')]);

        // A backend that ignores lifetimes hands back yesterday's bearer.
        $key = array_key_first($this->cacheStore) ?? 'seed';
        $this->cacheStore = [];
        $provider->getToken(OnboardingConfig::SCOPE_REFER);
        $key = (string)array_key_first($this->cacheStore);
        $this->cacheStore[$key] = (string)json_encode(['token' => 'stale', 'expires_at' => time() - 1]);

        $provider2 = $this->provider([$this->tokenResponse('tok-new')]);
        self::assertSame('tok-new', $provider2->getToken(OnboardingConfig::SCOPE_REFER));
    }

    public function testForgetDropsTheCachedTokenSoTheNextCallMints(): void
    {
        $provider = $this->provider([$this->tokenResponse('tok-1'), $this->tokenResponse('tok-2')]);

        self::assertSame('tok-1', $provider->getToken(OnboardingConfig::SCOPE_REFER));
        $provider->forget(OnboardingConfig::SCOPE_REFER);
        self::assertSame('tok-2', $provider->getToken(OnboardingConfig::SCOPE_REFER));
    }

    public function testRotatingTheClientIdInvalidatesTheCachedToken(): void
    {
        $first = $this->provider([$this->tokenResponse('tok-old')], 'client-id-aaa');
        self::assertSame('tok-old', $first->getToken(OnboardingConfig::SCOPE_REFER));

        $second = $this->provider([$this->tokenResponse('tok-new')], 'client-id-zzz');
        self::assertSame('tok-new', $second->getToken(OnboardingConfig::SCOPE_REFER));
    }

    public function testARefusedTokenThrowsRatherThanReturningAnEmptyBearer(): void
    {
        $provider = $this->provider([$this->response(401, '{"error":"invalid_client"}')]);

        $this->expectException(PayuOnboardingRequestException::class);
        $provider->getToken(OnboardingConfig::SCOPE_REFER);
    }

    public function testAServerErrorIsRetryableRatherThanFatal(): void
    {
        $provider = $this->provider([$this->response(503, '<html>gateway</html>')]);

        $this->expectException(PayuOnboardingRequestException::class);
        $this->expectExceptionMessageMatches('/retryable/i');
        $provider->getToken(OnboardingConfig::SCOPE_REFER);
    }

    /**
     * N-5. Some HTTP adapters put the request body in the exception message, and the
     * body of THIS request is the client secret. It must not survive into ours.
     */
    public function testTheClientSecretNeverReachesTheExceptionMessage(): void
    {
        $provider = $this->provider([new \RuntimeException('POST failed: client_secret=client-secret-bbb')]);

        try {
            $provider->getToken(OnboardingConfig::SCOPE_REFER);
            self::fail('Expected a transport exception');
        } catch (PayuOnboardingRequestException $e) {
            self::assertStringNotContainsString('client-secret-bbb', $e->getMessage());
            self::assertNull($e->getPrevious(), 'The original exception must not be chained: it carries the secret');
        }
    }
}
