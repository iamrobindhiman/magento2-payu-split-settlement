<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Credentials and endpoints for the PayU ONBOARDING API.
 *
 * This is a different credential from the one the split engine uses. The split
 * engine signs transaction calls with merchant_key + salt; onboarding uses an OAuth2
 * client_id/client_secret pair PayU issues per account. They are not interchangeable —
 * PayU's token endpoint rejects the merchant key as "unknown client" (verified
 * 2026-09-09). Hence a config class of its own rather than more getters on SplitConfig.
 *
 * The environment is deliberately NOT a separate toggle: it is read from the PayU
 * payment module the same way SplitConfig reads it, so onboarding can never be
 * pointed at production while payments are still in sandbox.
 */
class OnboardingConfig
{
    /** N-6: the whole feature ships dark and is switched on deliberately. */
    public const XML_PATH_ENABLED = 'rkd_payu/onboarding/enabled';
    public const XML_PATH_CLIENT_ID = 'rkd_payu/onboarding/client_id';
    public const XML_PATH_CLIENT_SECRET = 'rkd_payu/onboarding/client_secret';
    public const XML_PATH_PARENT_MID = 'rkd_payu/onboarding/parent_mid';
    public const XML_PATH_MONTHLY_VOLUME = 'rkd_payu/onboarding/monthly_expected_volume';
    public const XML_PATH_ALERT_EMAIL = 'rkd_payu/onboarding/alert_email';

    /**
     * PayU requires a monthly expected volume on create. The S-2 UAT children were created
     * with 60000 and accepted; used when the setting is blank.
     */
    private const DEFAULT_MONTHLY_VOLUME = 60000;

    /** Where onboarding alerts go when no dedicated address is configured. */
    private const XML_PATH_GENERAL_EMAIL = 'trans_email/ident_general/email';

    /**
     * The two scopes this product has. There is no third — PayU's Get Client Token
     * reference documents exactly these, and we hold both.
     *
     * They are NOT interchangeable, which is not documented anywhere and cost a
     * round of false conclusions: a token minted for one scope returns 401 on the
     * other's routes. Measured 2026-09-10:
     *   refer_child_merchant  -> POST create, PUT update, GET one child
     *   fetch_child_merchants -> GET {parentMid}/sub_accounts, and nothing else
     */
    public const SCOPE_REFER = 'refer_child_merchant';
    public const SCOPE_FETCH = 'fetch_child_merchants';

    /**
     * Token endpoints. Note these are on accounts.payu.in, a different host again
     * from the onboarding API itself.
     */
    private const TOKEN_ENDPOINTS = [
        SplitConfig::ENV_SANDBOX => 'https://uat-accounts.payu.in/oauth/token',
        'production' => 'https://accounts.payu.in/oauth/token',
    ];

    /**
     * Onboarding API roots.
     *
     * The production host is "onboarding.payu.in". PayU's own documentation prints
     * it as "oneboarding.payu.in", which DOES NOT RESOLVE (verified 2026-09-09).
     * Do not "correct" this back to the documented spelling.
     */
    private const API_ENDPOINTS = [
        SplitConfig::ENV_SANDBOX => 'https://uat-onepayuonboarding.payu.in/api/v3/product_accounts',
        'production' => 'https://onboarding.payu.in/api/v3/product_accounts',
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Sandbox unless the PayU payment module is explicitly in production.
     *
     * @return string
     */
    public function getEnvironment(): string
    {
        $environment = (string)$this->scopeConfig->getValue(SplitConfig::XML_PATH_ENVIRONMENT);
        return $environment === 'production' ? 'production' : SplitConfig::ENV_SANDBOX;
    }

    /**
     * @return bool
     */
    public function isSandbox(): bool
    {
        return $this->getEnvironment() === SplitConfig::ENV_SANDBOX;
    }

    /**
     * @return string
     */
    public function getClientId(): string
    {
        return $this->decrypt(self::XML_PATH_CLIENT_ID);
    }

    /**
     * @return string
     */
    public function getClientSecret(): string
    {
        return $this->decrypt(self::XML_PATH_CLIENT_SECRET);
    }

    /**
     * ALLYNOR's own parent MID. Every child must be created under it or it is not ours.
     *
     * @return string
     */
    public function getParentMid(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_PARENT_MID));
    }

    /**
     * @return int Rupees
     */
    public function getMonthlyExpectedVolume(): int
    {
        $volume = (int)$this->scopeConfig->getValue(self::XML_PATH_MONTHLY_VOLUME);
        return $volume > 0 ? $volume : self::DEFAULT_MONTHLY_VOLUME;
    }

    /**
     * The inbox that hears about vendors needing staff action: a failure, or the Schedule C
     * dashboard click. Falls back to the store's general contact.
     *
     * @return string
     */
    public function getAlertEmail(): string
    {
        $email = trim((string)$this->scopeConfig->getValue(self::XML_PATH_ALERT_EMAIL));
        return $email !== '' ? $email : trim((string)$this->scopeConfig->getValue(self::XML_PATH_GENERAL_EMAIL));
    }

    /**
     * Whether enough is configured to make any call at all. Checked before the cron
     * scan does any work, so a half-configured install logs one clear line instead of
     * failing per vendor.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->getClientId() !== ''
            && $this->getClientSecret() !== ''
            && $this->getParentMid() !== '';
    }

    /**
     * @return string
     */
    public function getTokenEndpoint(): string
    {
        return self::TOKEN_ENDPOINTS[$this->getEnvironment()];
    }

    /**
     * @return string
     */
    public function getApiEndpoint(): string
    {
        return self::API_ENDPOINTS[$this->getEnvironment()];
    }

    /**
     * @param string $uuid
     * @return string
     */
    public function getChildEndpoint(string $uuid): string
    {
        return $this->getApiEndpoint() . '/' . rawurlencode($uuid);
    }

    /**
     * The sub-account listing takes the parent MID in the path, not a uuid. Every
     * uuid candidate returns 404 — this cost a round of guessing on 2026-09-09.
     *
     * @return string
     */
    public function getSubAccountsEndpoint(): string
    {
        return $this->getApiEndpoint() . '/' . rawurlencode($this->getParentMid()) . '/sub_accounts';
    }

    /**
     * @param string $path
     * @return string
     */
    private function decrypt(string $path): string
    {
        $stored = (string)$this->scopeConfig->getValue($path);
        if ($stored === '') {
            return '';
        }
        return trim($this->encryptor->decrypt($stored));
    }
}
