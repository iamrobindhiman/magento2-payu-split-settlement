<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * SplitConfig resolves the merchant credentials (reused from payment/payu/*) and the
 * per-command, per-environment endpoints. The UAT/prod endpoint asymmetry
 * (get_split_info form=1 on UAT) and the "only explicit sandbox uses test endpoints"
 * rule are money-safety relevant, so they are pinned here.
 */
class SplitConfigTest extends TestCase
{
    /**
     * @param array<string, string> $map
     * @return SplitConfig
     */
    private function configWith(array $map): SplitConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $map[$path] ?? null
        );
        return new SplitConfig($scopeConfig);
    }

    public function testReadsTrimmedCredentials(): void
    {
        $config = $this->configWith([
            'payment/payu/merchant_key' => '  PRNT01  ',
            'payment/payu/salt' => "saltblob\n",
        ]);
        $this->assertSame('PRNT01', $config->getMerchantKey());
        $this->assertSame('saltblob', $config->getSalt());
    }

    public function testSandboxSelectsUatEndpoints(): void
    {
        $config = $this->configWith(['payment/payu/environment' => 'sandbox']);
        $this->assertTrue($config->isSandbox());
        $this->assertSame(
            'https://test.payu.in/merchant/postservice.php?form=2',
            $config->getEndpoint(SplitConfig::COMMAND_PAYMENT_SPLIT)
        );
        // get_split_info uses form=1 on UAT (documented asymmetry).
        $this->assertSame(
            'https://test.payu.in/merchant/postservice.php?form=1',
            $config->getEndpoint(SplitConfig::COMMAND_GET_SPLIT_INFO)
        );
        // release_settlement endpoint has no .php.
        $this->assertSame(
            'https://test.payu.in/merchant/postservice?form=2',
            $config->getEndpoint(SplitConfig::COMMAND_RELEASE_SETTLEMENT)
        );
    }

    public function testProductionSelectsInfoEndpoints(): void
    {
        $config = $this->configWith(['payment/payu/environment' => 'production']);
        $this->assertFalse($config->isSandbox());
        $this->assertSame(
            'https://info.payu.in/merchant/postservice.php?form=2',
            $config->getEndpoint(SplitConfig::COMMAND_PAYMENT_SPLIT)
        );
        $this->assertSame(
            'https://info.payu.in/merchant/postservice.php?form=2',
            $config->getEndpoint(SplitConfig::COMMAND_GET_SPLIT_INFO)
        );
    }

    public function testRefundCommandResolvesToPostserviceEndpoint(): void
    {
        $sandbox = $this->configWith(['payment/payu/environment' => 'sandbox']);
        $this->assertSame(
            'https://test.payu.in/merchant/postservice.php?form=2',
            $sandbox->getEndpoint(SplitConfig::COMMAND_CANCEL_REFUND_TRANSACTION)
        );
        $prod = $this->configWith(['payment/payu/environment' => 'production']);
        $this->assertSame(
            'https://info.payu.in/merchant/postservice.php?form=2',
            $prod->getEndpoint(SplitConfig::COMMAND_CANCEL_REFUND_TRANSACTION)
        );
    }

    public function testUnknownEnvironmentDefaultsToProductionEndpoints(): void
    {
        // Only an explicit "sandbox" hits test endpoints; empty/garbage resolves to production.
        $config = $this->configWith(['payment/payu/environment' => '']);
        $this->assertFalse($config->isSandbox());
        $this->assertStringStartsWith(
            'https://info.payu.in/',
            $config->getEndpoint(SplitConfig::COMMAND_PAYMENT_SPLIT)
        );
    }

    public function testUnknownCommandThrows(): void
    {
        $config = $this->configWith(['payment/payu/environment' => 'sandbox']);
        $this->expectException(\InvalidArgumentException::class);
        $config->getEndpoint('nonexistent_command');
    }

    public function testHasCredentialsTrueWhenBothPresent(): void
    {
        $config = $this->configWith([
            'payment/payu/merchant_key' => 'K',
            'payment/payu/salt' => 'S',
        ]);
        $this->assertTrue($config->hasCredentials());
    }

    public function testHasCredentialsFalseWhenSaltMissing(): void
    {
        $config = $this->configWith(['payment/payu/merchant_key' => 'K']);
        $this->assertFalse($config->hasCredentials());
    }

    public function testCeilingAndAlertDaysReadConfiguredValues(): void
    {
        $config = $this->configWith([
            'rkd_payu/split/ceiling_days' => '20',
            'rkd_payu/split/alert_days' => '18',
        ]);
        $this->assertSame(20, $config->getCeilingDays());
        $this->assertSame(18, $config->getAlertDays());
    }

    public function testCeilingAndAlertDaysFallBackToDefaultsWhenUnset(): void
    {
        // RBI-compliance default: PayU auto-settles at ~T+30, so split well before.
        $config = $this->configWith([]);
        $this->assertSame(25, $config->getCeilingDays());
        $this->assertSame(23, $config->getAlertDays());
    }

    public function testNonPositiveDayConfigFallsBackToDefault(): void
    {
        // A blank or zero value must not collapse the ceiling to 0 (which would split
        // every order immediately, defeating the trigger).
        $config = $this->configWith([
            'rkd_payu/split/ceiling_days' => '0',
            'rkd_payu/split/alert_days' => '',
        ]);
        $this->assertSame(25, $config->getCeilingDays());
        $this->assertSame(23, $config->getAlertDays());
    }

    public function testScanBatchSizeReadsConfiguredValue(): void
    {
        $config = $this->configWith(['rkd_payu/split/scan_batch_size' => '50']);
        $this->assertSame(50, $config->getScanBatchSize());
    }

    public function testScanBatchSizeFallsBackToDefault(): void
    {
        $this->assertSame(200, $this->configWith([])->getScanBatchSize());
        // Zero/blank must not yield an unbounded (page-size 0) scan.
        $this->assertSame(200, $this->configWith(['rkd_payu/split/scan_batch_size' => '0'])->getScanBatchSize());
    }
}
