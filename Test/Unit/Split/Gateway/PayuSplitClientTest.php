<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Gateway;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Model\Config as DiagnosticConfig;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitResult;
use RKD\PayuSplit\Split\Service\PayuResponseParser;
use RKD\PayuSplit\Split\Service\SplitHashBuilder;
use RKD\PayuSplit\Split\Service\SplitPayloadBuilder;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * The gateway is the only network boundary. These tests pin the wire contract:
 * the exact POST fields, that the hash is computed over the same var1 that is sent,
 * that var2 (child MID) is excluded from the release hash, and that a transport
 * failure throws (retryable) while a PayU business error (AGG-104) comes back as a
 * response (handled by the caller). No real network is touched.
 */
class PayuSplitClientTest extends TestCase
{
    private Json $json;

    protected function setUp(): void
    {
        $this->json = new Json();
    }

    /**
     * @param SplitConfig $config
     * @param Curl $curl
     * @return PayuSplitClient
     */
    private function makeClient(SplitConfig $config, Curl $curl): PayuSplitClient
    {
        $diagnosticConfig = $this->createMock(DiagnosticConfig::class);
        $diagnosticConfig->method('isLoggingEnabled')->willReturn(false);

        return new PayuSplitClient(
            $config,
            new SplitHashBuilder(),
            new SplitPayloadBuilder($this->json),
            new PayuResponseParser($this->json),
            $curl,
            $this->createMock(Logger::class),
            $diagnosticConfig
        );
    }

    /**
     * @param string $endpoint
     * @return SplitConfig
     */
    private function configReturning(string $endpoint): SplitConfig
    {
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $config->method('getSalt')->willReturn('saltvalue');
        $config->method('getEndpoint')->willReturn($endpoint);
        return $config;
    }

    /**
     * @return OrderSplitPlan
     */
    private function makePlan(): OrderSplitPlan
    {
        $result = new SplitResult(
            [12 => Money::fromPaise(67932)],
            Money::fromPaise(11988),
            Money::fromPaise(79920)
        );
        return new OrderSplitPlan(45, '000000045', $result, [12 => 'CHLD01']);
    }

    public function testPaymentSplitPostsCorrectFieldsAndParsesSuccess(): void
    {
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=2');
        $curl = $this->createMock(Curl::class);

        $captured = [];
        $curl->method('post')->willReturnCallback(
            static function (string $url, array $fields) use (&$captured): void {
                $captured = ['url' => $url, 'fields' => $fields];
            }
        );
        $curl->method('getBody')->willReturn('{"status":1,"message":"Splits creation successful."}');

        $response = $this->makeClient($config, $curl)->paymentSplit($this->makePlan(), '100000000000000002');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('https://test.payu.in/merchant/postservice.php?form=2', $captured['url']);
        $this->assertSame('PRNT01', $captured['fields']['key']);
        $this->assertSame('payment_split', $captured['fields']['command']);
        $this->assertSame('7', $captured['fields']['api_version']);
        $this->assertStringContainsString('CHLD01', $captured['fields']['var1']);
        // The hash must be computed over the EXACT var1 string that is sent.
        $this->assertSame(
            hash('sha512', 'PRNT01|payment_split|' . $captured['fields']['var1'] . '|saltvalue'),
            $captured['fields']['hash']
        );
    }

    public function testGetSplitInfoSendsPayuIdAsVar1WithNoApiVersion(): void
    {
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=1');
        $curl = $this->createMock(Curl::class);

        $captured = [];
        $curl->method('post')->willReturnCallback(
            static function (string $url, array $fields) use (&$captured): void {
                $captured = ['url' => $url, 'fields' => $fields];
            }
        );
        $curl->method('getBody')->willReturn("<pre>Array\n(\n    [status] => 1\n    [payuId] => 999\n)\n</pre>");

        $response = $this->makeClient($config, $curl)->getSplitInfo('999');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('https://test.payu.in/merchant/postservice.php?form=1', $captured['url']);
        $this->assertSame('get_split_info', $captured['fields']['command']);
        $this->assertSame('999', $captured['fields']['var1']);
        $this->assertArrayNotHasKey('api_version', $captured['fields']);
    }

    public function testReleaseSettlementSendsChildMidAsVar2ExcludedFromHash(): void
    {
        $config = $this->configReturning('https://test.payu.in/merchant/postservice?form=2');
        $curl = $this->createMock(Curl::class);

        $captured = [];
        $curl->method('post')->willReturnCallback(
            static function (string $url, array $fields) use (&$captured): void {
                $captured = ['url' => $url, 'fields' => $fields];
            }
        );
        $curl->method('getBody')->willReturn('{"status":1,"msg":"Release request is accepted"}');

        $response = $this->makeClient($config, $curl)->releaseSettlement('888', '9000001');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('https://test.payu.in/merchant/postservice?form=2', $captured['url']);
        $this->assertSame('release_settlement', $captured['fields']['command']);
        $this->assertSame('888', $captured['fields']['var1']);
        $this->assertSame('9000001', $captured['fields']['var2']);
        // var2 (child MID) is NOT part of the hash — only key|command|var1|salt.
        $this->assertSame(
            hash('sha512', 'PRNT01|release_settlement|888|saltvalue'),
            $captured['fields']['hash']
        );
    }

    public function testCancelRefundTransactionPostsVar2Var3Var8WithSplitHash(): void
    {
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=2');
        $curl = $this->createMock(Curl::class);

        $captured = [];
        $curl->method('post')->willReturnCallback(
            static function (string $url, array $fields) use (&$captured): void {
                $captured = ['url' => $url, 'fields' => $fields];
            }
        );
        // Refund response is keyed per merchant key (child leg + parent leg), E-12 shape.
        $curl->method('getBody')->willReturn(
            '{"CHLD01":{"status":1,"msg":"Refund Request Queued","request_id":"139931269","error_code":102},'
            . '"PRNT01":{"status":1,"msg":"Refund Request Queued","request_id":"139931270","error_code":102}}'
        );

        $var8 = '{"CHLD01":{"amount":511.36,"aggregatorRefundAmount":159.70}}';
        $response = $this->makeClient($config, $curl)
            ->cancelRefundTransaction('100000000000000003', 'R-42-1', '671.06', $var8);

        $this->assertSame('cancel_refund_transaction', $captured['fields']['command']);
        $this->assertSame('100000000000000003', $captured['fields']['var1']);
        $this->assertSame('R-42-1', $captured['fields']['var2']);
        $this->assertSame('671.06', $captured['fields']['var3']);
        $this->assertSame($var8, $captured['fields']['var8']);
        // Hash is the same postservice formula over key|command|var1|salt (var2/3/8 excluded).
        $this->assertSame(
            hash('sha512', 'PRNT01|cancel_refund_transaction|100000000000000003|saltvalue'),
            $captured['fields']['hash']
        );
        // The per-key legs are carried on the raw body for the refund parser.
        $raw = $response->raw();
        $this->assertIsArray($raw);
        $this->assertArrayHasKey('CHLD01', $raw);
        $this->assertArrayHasKey('PRNT01', $raw);
    }

    public function testCheckActionStatusSendsRequestIdAsVar1ExcludedFromExtrasInHash(): void
    {
        // The QUEUED → SUCCESS promotion poll: var1 is the refund leg's request_id.
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=2');
        $curl = $this->createMock(Curl::class);

        $captured = [];
        $curl->method('post')->willReturnCallback(
            static function (string $url, array $fields) use (&$captured): void {
                $captured = ['url' => $url, 'fields' => $fields];
            }
        );
        $curl->method('getBody')->willReturn('{"status":1,"transaction_details":{"139931269":{"status":"SUCCESS"}}}');

        $response = $this->makeClient($config, $curl)->checkActionStatus('139931269');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('check_action_status_txnid', $captured['fields']['command']);
        $this->assertSame('139931269', $captured['fields']['var1']);
        $this->assertSame(
            hash('sha512', 'PRNT01|check_action_status_txnid|139931269|saltvalue'),
            $captured['fields']['hash']
        );
    }

    public function testGetAggregatorTransactionsSendsDateWindowAndPagingWithVar1Hash(): void
    {
        // The crash-recovery reconciliation list: date-window + paging (NOT keyed by
        // mihpayid — the executor filters the returned rows by payuId). Hash is the
        // postservice formula over var1 (the start datetime); var2/3/4 are excluded.
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=2');
        $curl = $this->createMock(Curl::class);

        $captured = [];
        $curl->method('post')->willReturnCallback(
            static function (string $url, array $fields) use (&$captured): void {
                $captured = ['url' => $url, 'fields' => $fields];
            }
        );
        $curl->method('getBody')->willReturn('{"status":1,"Transaction_details":[]}');

        $response = $this->makeClient($config, $curl)
            ->getAggregatorTransactions('2026-06-12 00:00', '2026-06-12 23:59', 1, 100);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('get_aggregator_transactions', $captured['fields']['command']);
        $this->assertSame('2026-06-12 00:00', $captured['fields']['var1']);
        $this->assertSame('2026-06-12 23:59', $captured['fields']['var2']);
        $this->assertSame('1', $captured['fields']['var3']);
        $this->assertSame('100', $captured['fields']['var4']);
        $this->assertSame(
            hash('sha512', 'PRNT01|get_aggregator_transactions|2026-06-12 00:00|saltvalue'),
            $captured['fields']['hash']
        );
    }

    public function testTransportFailureThrowsRetryableException(): void
    {
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=1');
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willThrowException(new \RuntimeException('Connection timed out'));

        $this->expectException(PayuSplitRequestException::class);
        $this->makeClient($config, $curl)->getSplitInfo('123');
    }

    public function testPayuBusinessErrorComesBackAsResponseNotException(): void
    {
        // AGG-104 (already split) is a business outcome — it must be a response the
        // caller inspects, never a thrown exception.
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=2');
        $curl = $this->createMock(Curl::class);
        $curl->method('post');
        $curl->method('getBody')->willReturn(
            '{"status":0,"error_code":"AGG-104","error_desc":"Split already exists"}'
        );

        $response = $this->makeClient($config, $curl)->paymentSplit($this->makePlan(), '777');

        $this->assertTrue($response->isAlreadySplit());
        $this->assertFalse($response->isSuccess());
    }

    public function testHttpServerErrorThrowsRetryableTransportException(): void
    {
        // A PayU 5xx (HTML error page) must surface as a retryable transport failure,
        // not as an ambiguous status-less response.
        $config = $this->configReturning('https://test.payu.in/merchant/postservice.php?form=2');
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(503);
        $curl->method('getBody')->willReturn('<html><body>503 Service Unavailable</body></html>');

        $this->expectException(PayuSplitRequestException::class);
        $this->makeClient($config, $curl)->paymentSplit($this->makePlan(), '777');
    }
}
