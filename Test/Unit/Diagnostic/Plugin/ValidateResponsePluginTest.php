<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Diagnostic\Plugin;

use RKD\PayuSplit\Diagnostic\Plugin\ValidateResponsePlugin;
use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use RKD\PayuSplit\Model\Config;
use RKD\PayuSplit\Service\ResponseHashCalculator;
use PayUIndia\Payu\Model\Payu;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Hook 2 — wraps PayUIndia\Payu\Model\Payu::validateResponse with around-plugin.
 * Captures inputs + diagnoses which of the 6 silent-false paths fired.
 */
class ValidateResponsePluginTest extends TestCase
{
    /** @var Config&MockObject */
    private Config $config;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var Payu&MockObject */
    private Payu $subject;
    private ValidateResponsePlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(Payu::class);

        $this->plugin = new ValidateResponsePlugin(
            $this->config,
            new PayloadRedactor(false),
            $this->logger,
            new ResponseHashCalculator()
        );
    }

    public function testShortCircuitsWhenLoggingDisabled(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method($this->anything());

        $proceed = fn() => true;
        $result = $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success']
        );

        $this->assertTrue($result);
    }

    public function testLogsTrueResultWithMaskedHashesOnSuccess(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $proceed = fn() => true;

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('validateResponse'),
                $this->callback(function (array $context): bool {
                    $this->assertTrue($context['result']);
                    $this->assertSame('success', $context['payload']['status']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success', 'hash' => str_repeat('a', 128), 'txnid' => 'T1']
        );
    }

    public function testLogsFalseWithReasonStatusNotSuccess(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $proceed = fn() => false;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('validateResponse returned false'),
                $this->callback(function (array $context): bool {
                    $this->assertFalse($context['result']);
                    $this->assertSame('status_not_success', $context['failure_reason']);
                    $this->assertSame('failure', $context['payload']['status']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'failure', 'key' => 'k', 'hash' => 'h']
        );
    }

    public function testLogsFalseWithReasonHashMissing(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => $k === 'merchant_key' ? 'mk' : null
        );
        $proceed = fn() => false;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('hash_missing', $context['failure_reason']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success', 'key' => 'mk'] // no hash key
        );
    }

    public function testLogsFalseWithReasonKeyMismatch(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => $k === 'merchant_key' ? 'EXPECTED_KEY' : null
        );
        $proceed = fn() => false;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('key_mismatch', $context['failure_reason']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success', 'key' => 'WRONG_KEY', 'hash' => 'h']
        );
    }

    public function testCatchesUndefinedUdfKeyAndLogsAsFailureReason(): void
    {
        // Key insight: PHP 8 raises a Warning/Error on $returnParams['udf6'] when key absent.
        // Upstream's validateResponse reads udf1..udf10 unconditionally.
        // Our around-plugin must catch this and log it as a structured failure.
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => $k === 'merchant_key' ? 'mk' : null
        );

        $proceed = function () {
            // Simulate the upstream raising on undefined udf access
            throw new \ErrorException('Undefined array key "udf6"', 0, E_WARNING);
        };

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('undefined_udf_key', $context['failure_reason']);
                    $this->assertStringContainsString('udf6', $context['exception']);
                    return true;
                })
            );

        $result = $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success', 'key' => 'mk', 'hash' => 'h']
        );

        // Plugin returns false (matches upstream's silent-false behaviour) — does not re-throw
        $this->assertFalse($result);
    }

    public function testInfersHashMismatchWhenAllInputsValidButResultFalse(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => $k === 'merchant_key' ? 'mk' : null
        );
        $proceed = fn() => false;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('hash_mismatch', $context['failure_reason']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success', 'key' => 'mk', 'hash' => 'somehash']
        );
    }

    public function testClassifiesSplitFormulaFailureWhenSplitInfoPresent(): void
    {
        // When the inner Split\Plugin\ResponseHashPlugin returns false because
        // its split-formula recompute mismatched, this outer plugin sees the
        // boolean false and the splitInfo key. Failure_reason is refined so
        // the log distinguishes split-formula bugs from classic-formula bugs.
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => $k === 'merchant_key' ? 'mk' : null
        );
        $proceed = fn() => false;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('hash_mismatch_split', $context['failure_reason']);
                    $this->assertSame('split', $context['hash_formula']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            $proceed,
            ['status' => 'success', 'key' => 'mk', 'hash' => 'somehash', 'splitInfo' => '{"x":1}']
        );
    }

    public function testEveryLogContextCarriesComputedAndReceivedHashPrefixes(): void
    {
        // The whole point of Phase 1.5: future hash bugs are a 2-minute diagnosis,
        // not a recompute script. Every log entry — success, failure, exception —
        // must carry computed_hash_prefix + received_hash_prefix (16 chars each)
        // and the formula tag (split or classic).
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => match ($k) {
                'merchant_key' => 'mk',
                'salt'         => 'real-salt',
                default        => '',
            }
        );

        $assertContext = function (array $context): bool {
            $this->assertArrayHasKey('computed_hash_prefix', $context);
            $this->assertArrayHasKey('received_hash_prefix', $context);
            $this->assertArrayHasKey('hash_formula', $context);
            $this->assertSame(16, strlen($context['computed_hash_prefix']));
            $this->assertContains($context['hash_formula'], ['split', 'classic']);
            return true;
        };

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->anything(), $this->callback($assertContext));

        $this->plugin->aroundValidateResponse(
            $this->subject,
            fn() => true,
            ['status' => 'success', 'key' => 'mk', 'hash' => str_repeat('a', 128), 'txnid' => 'T1']
        );
    }

    public function testReceivedHashPrefixMarkedAbsentWhenHashMissing(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->subject->method('getConfigData')->willReturnCallback(
            fn ($k) => $k === 'merchant_key' ? 'mk' : ''
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('***absent***', $context['received_hash_prefix']);
                    return true;
                })
            );

        $this->plugin->aroundValidateResponse(
            $this->subject,
            fn() => false,
            ['status' => 'success', 'key' => 'mk'] // no hash
        );
    }
}
