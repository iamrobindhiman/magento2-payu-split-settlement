<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the diagnostic logging toggle. Default ON for local/staging, OFF for prod
 * (set via core_config_data on prod). When OFF, every observer + plugin short-circuits
 * before any log call so overhead is zero.
 */
class Config
{
    public const XML_PATH_LOG_ENABLED = 'rkd_payu/diagnostic/log_enabled';
    public const XML_PATH_REDACT_PII = 'rkd_payu/diagnostic/redact_pii';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isLoggingEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(
            self::XML_PATH_LOG_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * When true, customer PII (firstname/email/phone) is replaced with ***redacted***
     * in log entries. Default false (visible in logs) for local/staging diagnosis.
     */
    public function isPiiRedactionEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(
            self::XML_PATH_REDACT_PII,
            ScopeInterface::SCOPE_STORE
        );
    }
}
