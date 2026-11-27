<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Endpoints and credential handling. The production host assertion is deliberate:
 * PayU's own documentation prints it as "oneboarding.payu.in", which does not
 * resolve. This test exists so that anyone who "fixes" the spelling back to the
 * documented one breaks a test instead of production.
 */
class OnboardingConfigTest extends TestCase
{
    /**
     * @param array<string, string|null> $values
     * @return OnboardingConfig
     */
    private function config(array $values): OnboardingConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => (bool)($values[$path] ?? false)
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        // Stand-in for Magento's encryption: the stored value is "enc:" + plaintext.
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $value) => str_starts_with($value, 'enc:') ? substr($value, 4) : $value
        );

        return new OnboardingConfig($scopeConfig, $encryptor);
    }

    public function testOnboardingIsOffUntilItIsTurnedOn(): void
    {
        self::assertFalse($this->config([])->isEnabled());
    }

    public function testTheEnvironmentFollowsThePaymentModuleRatherThanASeparateToggle(): void
    {
        $sandbox = $this->config([SplitConfig::XML_PATH_ENVIRONMENT => 'sandbox']);
        $production = $this->config([SplitConfig::XML_PATH_ENVIRONMENT => 'production']);

        self::assertTrue($sandbox->isSandbox());
        self::assertFalse($production->isSandbox());
    }

    public function testAnUnsetEnvironmentFallsBackToSandboxNotProduction(): void
    {
        self::assertTrue($this->config([])->isSandbox());
    }

    public function testSandboxEndpointsAreTheUatHosts(): void
    {
        $config = $this->config([SplitConfig::XML_PATH_ENVIRONMENT => 'sandbox']);

        self::assertSame('https://uat-accounts.payu.in/oauth/token', $config->getTokenEndpoint());
        self::assertSame(
            'https://uat-onepayuonboarding.payu.in/api/v3/product_accounts',
            $config->getApiEndpoint()
        );
    }

    /**
     * PayU documents the production host as "oneboarding.payu.in". It does not
     * resolve. Do not "correct" this.
     */
    public function testTheProductionHostIsTheOneThatActuallyResolves(): void
    {
        $config = $this->config([SplitConfig::XML_PATH_ENVIRONMENT => 'production']);

        self::assertSame(
            'https://onboarding.payu.in/api/v3/product_accounts',
            $config->getApiEndpoint()
        );
        self::assertStringNotContainsString('oneboarding', $config->getApiEndpoint());
    }

    public function testCredentialsAreDecryptedRatherThanReadRaw(): void
    {
        $config = $this->config([
            OnboardingConfig::XML_PATH_CLIENT_ID => 'enc:the-client-id',
            OnboardingConfig::XML_PATH_CLIENT_SECRET => 'enc:the-client-secret',
        ]);

        self::assertSame('the-client-id', $config->getClientId());
        self::assertSame('the-client-secret', $config->getClientSecret());
    }

    public function testTheSubAccountsPathTakesTheParentMidNotAUuid(): void
    {
        $config = $this->config([
            SplitConfig::XML_PATH_ENVIRONMENT => 'sandbox',
            OnboardingConfig::XML_PATH_PARENT_MID => '9000100',
        ]);

        self::assertSame(
            'https://uat-onepayuonboarding.payu.in/api/v3/product_accounts/9000100/sub_accounts',
            $config->getSubAccountsEndpoint()
        );
    }

    public function testAChildUuidIsUrlEncodedIntoThePath(): void
    {
        $config = $this->config([SplitConfig::XML_PATH_ENVIRONMENT => 'sandbox']);

        self::assertSame(
            'https://uat-onepayuonboarding.payu.in/api/v3/product_accounts/0000-0000-00000001',
            $config->getChildEndpoint('0000-0000-00000001')
        );
    }

    public function testIsConfiguredRequiresBothCredentialsAndTheParentMid(): void
    {
        $complete = $this->config([
            OnboardingConfig::XML_PATH_CLIENT_ID => 'enc:id',
            OnboardingConfig::XML_PATH_CLIENT_SECRET => 'enc:secret',
            OnboardingConfig::XML_PATH_PARENT_MID => '9000100',
        ]);
        $noParent = $this->config([
            OnboardingConfig::XML_PATH_CLIENT_ID => 'enc:id',
            OnboardingConfig::XML_PATH_CLIENT_SECRET => 'enc:secret',
        ]);

        self::assertTrue($complete->isConfigured());
        self::assertFalse($noParent->isConfigured());
        self::assertFalse($this->config([])->isConfigured());
    }

    public function testTheTwoScopesAreDistinct(): void
    {
        self::assertNotSame(OnboardingConfig::SCOPE_REFER, OnboardingConfig::SCOPE_FETCH);
        self::assertSame('refer_child_merchant', OnboardingConfig::SCOPE_REFER);
        self::assertSame('fetch_child_merchants', OnboardingConfig::SCOPE_FETCH);
    }
}
