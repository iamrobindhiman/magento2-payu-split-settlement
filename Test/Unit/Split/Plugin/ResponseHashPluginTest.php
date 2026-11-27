<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Plugin;

use RKD\PayuSplit\Service\ResponseHashCalculator;
use RKD\PayuSplit\Split\Plugin\ResponseHashPlugin;
use PayUIndia\Payu\Model\Payu;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Around-plugin on PayUIndia\Payu\Model\Payu::validateResponse.
 *
 * Why this exists: when the merchant has split-settlement enabled on PayU's side,
 * the response carries a `splitInfo` field AND PayU's response hash formula switches
 * to include `splitInfo` after `status`. The upstream module is unaware — it always
 * uses the classic formula, so 100% of transactions fail with hash_mismatch.
 *
 * This plugin intercepts ONLY when splitInfo is present in the payload, validates
 * with the split-aware formula, and returns the boolean directly without calling
 * proceed(). When splitInfo is absent it short-circuits to proceed() — upstream's
 * classic-formula logic remains the source of truth for non-split merchants.
 */
class ResponseHashPluginTest extends TestCase
{
    private const SALT = 'sandbox-salt-1234';
    private const KEY  = 'gtKFFx';

    /** @var Payu&MockObject */
    private Payu $subject;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    private ResponseHashPlugin $plugin;
    private ResponseHashCalculator $calc;

    protected function setUp(): void
    {
        $this->subject = $this->createMock(Payu::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->calc = new ResponseHashCalculator();
        $this->plugin = new ResponseHashPlugin($this->calc, $this->logger);

        $this->subject->method('getConfigData')->willReturnCallback(static function (string $k): string {
            return match ($k) {
                'merchant_key' => self::KEY,
                'salt'         => self::SALT,
                default        => '',
            };
        });
    }

    public function testPassesThroughToUpstreamWhenSplitInfoAbsent(): void
    {
        $payload = $this->basePayload();
        $proceedCalled = false;
        $proceed = function (array $p) use (&$proceedCalled): bool {
            $proceedCalled = true;
            return true;
        };

        $result = $this->plugin->aroundValidateResponse($this->subject, $proceed, $payload);

        $this->assertTrue($proceedCalled, 'Plugin must defer to upstream when splitInfo absent');
        $this->assertTrue($result);
    }

    public function testReturnsTrueWhenSplitHashMatches(): void
    {
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        // Compute the hash PayU *should* be sending and stuff it into the payload.
        $payload['hash'] = $this->calc->compute($payload, self::SALT, self::KEY);

        $proceed = function (): bool {
            self::fail('Plugin must NOT call proceed() when splitInfo present — upstream formula is wrong');
        };

        $this->assertTrue($this->plugin->aroundValidateResponse($this->subject, $proceed, $payload));
    }

    public function testReturnsFalseWhenSplitHashMismatches(): void
    {
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        $payload['hash'] = str_repeat('0', 128); // wrong hash

        $proceed = function (): bool {
            self::fail('Plugin must NOT call proceed() when splitInfo present');
        };

        $this->assertFalse($this->plugin->aroundValidateResponse($this->subject, $proceed, $payload));
    }

    public function testReturnsFalseWhenStatusNotSuccess(): void
    {
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        $payload['status'] = 'failure';
        $payload['hash'] = str_repeat('a', 128);

        $proceed = function (): bool {
            self::fail('Should not proceed when status not success');
        };

        $this->assertFalse($this->plugin->aroundValidateResponse($this->subject, $proceed, $payload));
    }

    public function testReturnsFalseWhenMerchantKeyMismatches(): void
    {
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        $payload['key'] = 'IMPOSTOR';

        $proceed = function (): bool {
            self::fail('Should not proceed when key mismatch');
        };

        $this->assertFalse($this->plugin->aroundValidateResponse($this->subject, $proceed, $payload));
    }

    public function testReturnsFalseWhenHashMissing(): void
    {
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        unset($payload['hash']);

        $proceed = function (): bool {
            self::fail('Should not proceed when hash missing');
        };

        $this->assertFalse($this->plugin->aroundValidateResponse($this->subject, $proceed, $payload));
    }

    public function testHashComparisonIsCaseInsensitive(): void
    {
        // sha512 output from hash() is lowercase, but PayU's response field has been
        // observed both ways across integrations. Compare case-insensitively to be safe.
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        $payload['hash'] = strtoupper($this->calc->compute($payload, self::SALT, self::KEY));

        $this->assertTrue($this->plugin->aroundValidateResponse($this->subject, fn() => false, $payload));
    }

    public function testNeverLogsItself(): void
    {
        // The diagnostic ValidateResponsePlugin (outer, sortOrder 10) owns all logging.
        // This inner plugin must return silently — both success and failure paths —
        // to avoid double-logging the same validation event.
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitDone","splitSegments":[]}';
        $payload['hash'] = str_repeat('0', 128); // mismatch path

        $this->logger->expects($this->never())->method($this->anything());
        $this->plugin->aroundValidateResponse($this->subject, fn() => true, $payload);
    }

    public function testNeverLogsOnSplitMatch(): void
    {
        $payload = $this->basePayload();
        $payload['splitInfo'] = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        $payload['hash'] = $this->calc->compute($payload, self::SALT, self::KEY);

        $this->logger->expects($this->never())->method($this->anything());
        $this->plugin->aroundValidateResponse($this->subject, fn() => false, $payload);
    }

    /**
     * @return array<string, string>
     */
    private function basePayload(): array
    {
        return [
            'status'      => 'success',
            'key'         => self::KEY,
            'txnid'       => '59',
            'amount'      => '692.56',
            'productinfo' => '59',
            'firstname'   => 'John',
            'email'       => 'john@example.com',
            'udf1'        => 'udf1value',
            'udf2'        => '', 'udf3' => '', 'udf4' => '',
            'udf5'        => 'Magento_v.2.4.6',
            'udf6'        => '', 'udf7' => '', 'udf8' => '', 'udf9' => '', 'udf10' => '',
        ];
    }
}
