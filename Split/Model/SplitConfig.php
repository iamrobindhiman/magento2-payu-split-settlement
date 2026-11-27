<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resolves the credentials and endpoints the split engine needs. Credentials are
 * read from the PayU payment module's own config (payment/payu/*) — the split
 * engine MUST act on the same merchant and environment the gateway used, so reusing
 * those values keeps them from drifting out of a separate toggle.
 *
 * The salt is read raw (the PayU salt config field has no backend encryption model;
 * the proven RKD_PayuSplit ResponseHashPlugin and the sandbox lab both read it as-is).
 * Per-command, per-environment endpoints follow docs/payu/kb/split-api.md §11 —
 * note get_split_info uses form=1 on UAT but form=2 on production.
 */
class SplitConfig
{
    public const XML_PATH_ENVIRONMENT = 'payment/payu/environment';
    public const XML_PATH_MERCHANT_KEY = 'payment/payu/merchant_key';
    public const XML_PATH_SALT = 'payment/payu/salt';

    public const XML_PATH_CEILING_DAYS = 'rkd_payu/split/ceiling_days';
    public const XML_PATH_ALERT_DAYS = 'rkd_payu/split/alert_days';
    public const XML_PATH_SCAN_BATCH_SIZE = 'rkd_payu/split/scan_batch_size';
    public const XML_PATH_ENABLE_REARM_REFUND = 'rkd_payu/split/enable_rearm_refund';
    public const XML_PATH_RELEASE_ENABLED = 'rkd_payu/split/release_enabled';

    /** Split before PayU auto-settles the aggregator txn (~T+30) — matrix M-1. */
    private const DEFAULT_CEILING_DAYS = 25;
    /** Raise a compliance alarm before the ceiling lands so an operator can act. */
    private const DEFAULT_ALERT_DAYS = 23;
    /** Headers processed per cron run — bounds memory + gateway calls per tick. */
    private const DEFAULT_SCAN_BATCH_SIZE = 200;

    public const ENV_SANDBOX = 'sandbox';

    public const COMMAND_PAYMENT_SPLIT = 'payment_split';
    public const COMMAND_GET_SPLIT_INFO = 'get_split_info';
    public const COMMAND_RELEASE_SETTLEMENT = 'release_settlement';
    public const COMMAND_CANCEL_REFUND_TRANSACTION = 'cancel_refund_transaction';
    /**
     * Refund-leg status poll (var1 = request_id). The June-2026 KB advises this name; the
     * older aggregator-prefixed variant appears deprecated/unified (settlement-refunds.md).
     */
    public const COMMAND_CHECK_ACTION_STATUS = 'check_action_status_txnid';
    /** Aggregator transaction list — date-window + paging (var1=from, var2=to, var3=page, var4=size). */
    public const COMMAND_GET_AGGREGATOR_TRANSACTIONS = 'get_aggregator_transactions';

    /**
     * Endpoint URLs keyed by environment then command (docs/payu/kb/split-api.md §11).
     */
    private const ENDPOINTS = [
        self::ENV_SANDBOX => [
            self::COMMAND_PAYMENT_SPLIT => 'https://test.payu.in/merchant/postservice.php?form=2',
            self::COMMAND_GET_SPLIT_INFO => 'https://test.payu.in/merchant/postservice.php?form=1',
            // release_settlement uses NO .php — per PayU's release_settlement doc
            // (docs/payu/kb/settlement-refunds.md §endpoint). Distinct from cancel_refund /
            // split / get_split_info which DO use .php. Unverifiable in UAT (E-07: sandbox has
            // no settlement lifecycle; both variants return the same generic error) → confirm
            // at the O-11 prod release-verify before go-live.
            self::COMMAND_RELEASE_SETTLEMENT => 'https://test.payu.in/merchant/postservice?form=2',
            self::COMMAND_CANCEL_REFUND_TRANSACTION => 'https://test.payu.in/merchant/postservice.php?form=2',
            self::COMMAND_CHECK_ACTION_STATUS => 'https://test.payu.in/merchant/postservice.php?form=2',
            self::COMMAND_GET_AGGREGATOR_TRANSACTIONS => 'https://test.payu.in/merchant/postservice.php?form=2',
        ],
        'production' => [
            self::COMMAND_PAYMENT_SPLIT => 'https://info.payu.in/merchant/postservice.php?form=2',
            self::COMMAND_GET_SPLIT_INFO => 'https://info.payu.in/merchant/postservice.php?form=2',
            // See the sandbox note above — release_settlement uses NO .php (PayU doc), O-11 prod-verify.
            self::COMMAND_RELEASE_SETTLEMENT => 'https://info.payu.in/merchant/postservice?form=2',
            self::COMMAND_CANCEL_REFUND_TRANSACTION => 'https://info.payu.in/merchant/postservice.php?form=2',
            self::COMMAND_CHECK_ACTION_STATUS => 'https://info.payu.in/merchant/postservice.php?form=2',
            self::COMMAND_GET_AGGREGATOR_TRANSACTIONS => 'https://info.payu.in/merchant/postservice.php?form=2',
        ],
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return string Parent (aggregator) merchant key
     */
    public function getMerchantKey(): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_MERCHANT_KEY,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * @return string Parent merchant salt (raw — not decrypted)
     */
    public function getSalt(): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_SALT,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * @return string The configured PayU environment value
     */
    public function getEnvironment(): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * Only an explicit "sandbox" uses the UAT (test.payu.in) endpoints; anything else
     * — including production or a misconfigured/empty value — resolves to production.
     *
     * @return bool
     */
    public function isSandbox(): bool
    {
        return $this->getEnvironment() === self::ENV_SANDBOX;
    }

    /**
     * @param string $command One of the COMMAND_* constants
     * @return string The endpoint URL for the current environment
     * @throws \InvalidArgumentException When the command has no known endpoint
     */
    public function getEndpoint(string $command): string
    {
        $environment = $this->isSandbox() ? self::ENV_SANDBOX : 'production';
        if (!isset(self::ENDPOINTS[$environment][$command])) {
            throw new \InvalidArgumentException(
                sprintf('No PayU split endpoint configured for command "%s".', $command)
            );
        }
        return self::ENDPOINTS[$environment][$command];
    }

    /**
     * True when both credentials are present — the split engine must not attempt a
     * call without them (it would produce an AGG-300 instead of a clear local error).
     *
     * @return bool
     */
    public function hasCredentials(): bool
    {
        return $this->getMerchantKey() !== '' && $this->getSalt() !== '';
    }

    /**
     * The T+N-day hard ceiling: the split MUST fire by this age even if no vendor has
     * shipped, so PayU never auto-settles an un-split aggregator txn (matrix M-1, the
     * RBI launch-gate control). A non-positive config value falls back to the default.
     *
     * @return int
     */
    public function getCeilingDays(): int
    {
        return $this->positiveOrDefault(self::XML_PATH_CEILING_DAYS, self::DEFAULT_CEILING_DAYS);
    }

    /**
     * Whether the break-glass "re-arm refund" action is enabled (A7-3). Default OFF: its
     * double-refund safety rests on PayU's unproven duplicate-var2-on-rejection semantics
     * (open question H-6) — the money review gated it OFF until PayU confirms. Retry-split and
     * the audit log are unaffected by this flag.
     *
     * @return bool
     */
    public function isRearmRefundEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_REARM_REFUND, ScopeInterface::SCOPE_STORE);
    }

    /**
     * The age at which an unsplit order raises a compliance alert (before the
     * ceiling lands), so an operator can investigate. Non-positive → default.
     *
     * @return int
     */
    public function getAlertDays(): int
    {
        return $this->positiveOrDefault(self::XML_PATH_ALERT_DAYS, self::DEFAULT_ALERT_DAYS);
    }

    /**
     * The master release kill-switch (A5c). Default OFF: NOTHING in the release path — neither
     * the release executor (A5c-3) nor the readback recon (A5c-5) — moves a segment toward
     * RELEASED until this is explicitly enabled. It exists because release is PROD-VERIFY only
     * (E-07: UAT cannot simulate settlement→release); it stays OFF until the O-11 own-account
     * verification has calibrated the readback and proven the end-to-end release path.
     *
     * @return bool
     */
    public function isReleaseEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_RELEASE_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Max split headers a single trigger-scan run processes (oldest first). Bounds the
     * collection load and the sequential gateway calls per cron tick so a large PENDING
     * backlog cannot stall the run; the remainder is picked up on the next tick. A
     * non-positive config value falls back to the default (never an unbounded scan).
     *
     * @return int
     */
    public function getScanBatchSize(): int
    {
        return $this->positiveOrDefault(self::XML_PATH_SCAN_BATCH_SIZE, self::DEFAULT_SCAN_BATCH_SIZE);
    }

    /**
     * @param string $path
     * @param int $default
     * @return int
     */
    private function positiveOrDefault(string $path, int $default): int
    {
        $value = (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : $default;
    }
}
