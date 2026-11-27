<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Gateway;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingNotSentException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingCredentialsException;
use RKD\PayuSplit\Split\Gateway\HttpClientFactory;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingTokenProvider;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Service\OnboardingPayloadBuilder;
use Laminas\Http\Client as LaminasHttpClient;
use Laminas\Http\Response;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wire contract of the onboarding gateway. The one that is easy to get
 * wrong, and that cost a wrong conclusion when it was got wrong, is SCOPE ROUTING:
 * the listing needs fetch_child_merchants and everything else needs
 * refer_child_merchant. Using the wrong one returns 401, which reads exactly like
 * "PayU will not let us do this" rather than "we asked with the wrong key".
 *
 * No network is touched.
 */
class PayuOnboardingClientTest extends TestCase
{
    private const API = 'https://uat-onepayuonboarding.payu.in/api/v3/product_accounts';
    private const UUID = '0000-0000-00000001-0000-000000000001';

    /** @var array<int, array{method: string, uri: string, body: string|null}> */
    private array $sent = [];

    /** @var array<int, string> */
    private array $tokenScopesRequested = [];

    /** @var array<int, string> */
    private array $forgottenScopes = [];

    protected function setUp(): void
    {
        $this->sent = [];
        $this->tokenScopesRequested = [];
        $this->forgottenScopes = [];
    }

    /**
     * @param array<int, Response|\Throwable> $responses
     * @return PayuOnboardingClient
     */
    private ?\Throwable $tokenFailure = null;

    /**
     * Wave-4 integrity F1: no token means nothing was sent, and a create must be able to tell that
     * apart from a lost answer.
     */
    public function testATokenFailureIsReportedAsNotSent(): void
    {
        $this->tokenFailure = new PayuOnboardingRequestException('PayU token endpoint returned HTTP 503 — retryable.');
        $client = $this->client([]);

        $this->expectException(PayuOnboardingNotSentException::class);
        $client->listSubAccounts();
    }

    public function testARefusedCredentialStaysACredentialsProblem(): void
    {
        $this->tokenFailure = new PayuOnboardingCredentialsException('refused');
        $client = $this->client([]);

        $this->expectException(PayuOnboardingCredentialsException::class);
        $client->listSubAccounts();
    }

    private function client(array $responses): PayuOnboardingClient
    {
        $config = $this->createMock(OnboardingConfig::class);
        $config->method('getApiEndpoint')->willReturn(self::API);
        $config->method('getParentMid')->willReturn('9000100');
        $config->method('getChildEndpoint')
            ->willReturnCallback(static fn (string $uuid) => self::API . '/' . rawurlencode($uuid));
        $config->method('getSubAccountsEndpoint')->willReturn(self::API . '/9000100/sub_accounts');

        $tokenProvider = $this->createMock(PayuOnboardingTokenProvider::class);
        $tokenProvider->method('getToken')->willReturnCallback(function (string $scope): string {
            if ($this->tokenFailure !== null) {
                throw $this->tokenFailure;
            }
            $this->tokenScopesRequested[] = $scope;
            return 'token-for-' . $scope;
        });
        $tokenProvider->method('forget')->willReturnCallback(function (string $scope): void {
            $this->forgottenScopes[] = $scope;
        });

        $payloadConfig = $this->createMock(OnboardingConfig::class);
        $payloadConfig->method('getParentMid')->willReturn('9000100');

        return new PayuOnboardingClient(
            $config,
            $tokenProvider,
            new OnboardingPayloadBuilder($payloadConfig),
            $this->httpClientFactory($responses),
            new Json(),
            $this->createMock(Logger::class)
        );
    }

    /**
     * @param array<int, Response|\Throwable> $responses
     * @return HttpClientFactory&MockObject
     */
    private function httpClientFactory(array $responses): HttpClientFactory
    {
        $state = ['method' => '', 'uri' => '', 'body' => null];

        $http = $this->createMock(LaminasHttpClient::class);
        $http->method('setUri')->willReturnCallback(function ($uri) use (&$state, $http) {
            $state['uri'] = (string)$uri;
            return $http;
        });
        $http->method('setMethod')->willReturnCallback(function ($method) use (&$state, $http) {
            $state['method'] = (string)$method;
            return $http;
        });
        $http->method('setRawBody')->willReturnCallback(function ($body) use (&$state, $http) {
            $state['body'] = (string)$body;
            return $http;
        });
        $http->method('setOptions')->willReturnSelf();
        $http->method('setHeaders')->willReturnSelf();
        $http->method('send')->willReturnCallback(function () use (&$state, &$responses) {
            $this->sent[] = $state;
            $state['body'] = null;
            $next = array_shift($responses);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        });

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturn($http);
        return $factory;
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
     * @return ChildMerchantDraft
     */
    private function draft(): ChildMerchantDraft
    {
        return new ChildMerchantDraft(
            'Ortho Supplies',
            'vendor@example.invalid',
            '9000000000',
            'Ortho Supplies Pvt Ltd',
            2,
            'AAAPA0000A',
            'Ortho Supplies',
            60000
        );
    }

    public function testCreatePostsToTheAccountsRootUnderTheReferScope(): void
    {
        $client = $this->client([$this->response(200, '{"product_account":{"uuid":"u"}}')]);
        $client->createChildMerchant($this->draft());

        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame(self::API, $this->sent[0]['uri']);
        self::assertSame([OnboardingConfig::SCOPE_REFER], $this->tokenScopesRequested);
    }

    public function testCreateSendsTheParentMidOnTheWire(): void
    {
        $client = $this->client([$this->response(200, '{"product_account":{"uuid":"u"}}')]);
        $client->createChildMerchant($this->draft());

        $body = json_decode((string)$this->sent[0]['body'], true);
        self::assertSame('9000100', $body['product_account']['aggregator_parent_mid']);
    }

    public function testAttachBankPutsToTheChildUnderTheReferScope(): void
    {
        $client = $this->client([$this->response(200, '{"product_account":{"uuid":"u"}}')]);
        $client->attachBankDetail(self::UUID, '50100000000000', 'HDFC0000000', 'Ortho Supplies');

        self::assertSame('PUT', $this->sent[0]['method']);
        self::assertSame(self::API . '/' . rawurlencode(self::UUID), $this->sent[0]['uri']);
        self::assertSame([OnboardingConfig::SCOPE_REFER], $this->tokenScopesRequested);
    }

    public function testFetchOneChildGetsTheChildUnderTheReferScope(): void
    {
        $client = $this->client([$this->response(200, '{"product_account":{"uuid":"u"}}')]);
        $client->fetchChildMerchant(self::UUID);

        self::assertSame('GET', $this->sent[0]['method']);
        self::assertSame(self::API . '/' . rawurlencode(self::UUID), $this->sent[0]['uri']);
        self::assertSame([OnboardingConfig::SCOPE_REFER], $this->tokenScopesRequested);
    }

    /**
     * The measured exception to the rule. Asking for the listing with the refer scope
     * returns 401 — which is what made a previous round conclude, wrongly, that we
     * could only create children and never read them.
     */
    public function testTheListingUsesTheFetchScopeAndTheParentMidPath(): void
    {
        $client = $this->client([$this->response(200, '{"child_merchants":{"product_accounts":[]}}')]);
        $client->listSubAccounts();

        self::assertSame('GET', $this->sent[0]['method']);
        self::assertSame(self::API . '/9000100/sub_accounts', $this->sent[0]['uri']);
        self::assertSame([OnboardingConfig::SCOPE_FETCH], $this->tokenScopesRequested);
    }

    public function testAGetCarriesNoRequestBody(): void
    {
        $client = $this->client([$this->response(200, '{"product_account":{"uuid":"u"}}')]);
        $client->fetchChildMerchant(self::UUID);

        self::assertNull($this->sent[0]['body']);
    }

    /**
     * T-10: a token that expires mid-run must be invisible to the caller.
     */
    public function testA401RefreshesTheTokenAndRetriesOnce(): void
    {
        $client = $this->client([
            $this->response(401, '{"status":"Unauthorized"}'),
            $this->response(200, '{"product_account":{"uuid":"u"}}'),
        ]);

        $response = $client->fetchChildMerchant(self::UUID);

        self::assertTrue($response->isSuccess());
        self::assertCount(2, $this->sent);
        self::assertSame([OnboardingConfig::SCOPE_REFER], $this->forgottenScopes);
    }

    /**
     * A second 401 is a real authorisation problem, most likely the wrong scope for
     * the route. Retrying past it would hammer the token endpoint and never succeed.
     */
    public function testAPersistent401IsReturnedRatherThanRetriedForever(): void
    {
        $client = $this->client([
            $this->response(401, '{"status":"Unauthorized"}'),
            $this->response(401, '{"status":"Unauthorized"}'),
        ]);

        $response = $client->listSubAccounts();

        self::assertTrue($response->isUnauthorized());
        self::assertCount(2, $this->sent);
        self::assertSame('HTTP 401: Unauthorized', $response->errorSummary());
    }

    /**
     * A business refusal is an ANSWER. "Bank details already submitted" tells the
     * caller the work is done; throwing it would make a retry loop out of a success.
     */
    public function testTheOneShotBankRefusalComesBackAsAResponseNotAnException(): void
    {
        $client = $this->client([
            $this->response(422, '{"errors":{"bank_details":["Bank details already submitted"]}}'),
        ]);

        $response = $client->attachBankDetail(self::UUID, '50100000000000', 'HDFC0000000', 'Ortho');

        self::assertFalse($response->isSuccess());
        self::assertSame(['Bank details already submitted'], $response->errorMessages());
    }

    public function testAServerErrorThrowsSoTheCronRetriesWithStateUntouched(): void
    {
        $client = $this->client([$this->response(502, '<html>bad gateway</html>')]);

        $this->expectException(PayuOnboardingRequestException::class);
        $this->expectExceptionMessageMatches('/retryable/i');
        $client->fetchChildMerchant(self::UUID);
    }

    /**
     * N-5: the bank call's body is the vendor's settlement account number. Adapters
     * put request bodies in exception messages; ours must not pass that through.
     */
    public function testTheSettlementAccountNumberNeverReachesTheExceptionMessage(): void
    {
        $client = $this->client([new \RuntimeException('PUT failed, body: {"bank_account_number":"50100000000000"}')]);

        try {
            $client->attachBankDetail(self::UUID, '50100000000000', 'HDFC0000000', 'Ortho');
            self::fail('Expected a transport exception');
        } catch (PayuOnboardingRequestException $e) {
            self::assertStringNotContainsString('50100000000000', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
