<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use Magento\Framework\Model\AbstractModel;

/**
 * A Webkul seller's PayU child sub-merchant — the account that receives that
 * vendor's split share. One row per seller.
 *
 * The row is created the moment a vendor is approved, BEFORE PayU knows anything
 * about them, so it doubles as the onboarding record: `onboarding_state` carries
 * the vendor from approval through to activation, and `payu_merchant_key` stays
 * null until PayU has actually created the account.
 *
 * Readiness is deliberately NOT decided here — ask
 * {@see \RKD\PayuSplit\Split\Service\SubmerchantReadiness}. One rule, one place to
 * correct once we have seen a genuinely activated sub-merchant in production.
 */
class Submerchant extends AbstractModel
{
    /**
     * KYC lifecycle states for a child sub-merchant. Canonical home for the enum: both the
     * form's dropdown source and the save-time validator read these.
     */
    public const KYC_PENDING = 'pending';
    public const KYC_VERIFIED = 'verified';
    public const KYC_REJECTED = 'rejected';

    /**
     * @var string[]
     */
    public const KYC_STATUSES = [self::KYC_PENDING, self::KYC_VERIFIED, self::KYC_REJECTED];

    /**
     * Onboarding lifecycle. A vendor walks these in order; FAILED can be entered from any
     * of them and returns to QUEUED on retry.
     *
     * NOT_STARTED    vendor not approved yet
     * QUEUED         approved; the approval observer wrote the marker, no PayU call made yet
     * CREATED        sub-merchant exists at PayU; mid, uuid and merchant key stored
     * BANK_ATTACHED  settlement bank sent; PayU has begun its penny-drop
     * AWAITING_ESIGN in the staff work queue — waiting on the Schedule C dashboard click
     *                and then the vendor's own OTP signature
     * ACTIVE         PayU has activated the sub-merchant; the vendor may now be paid
     * FAILED         an attempt failed; last_error holds the reason and it is retryable
     */
    public const STATE_NOT_STARTED = 'NOT_STARTED';
    public const STATE_QUEUED = 'QUEUED';
    public const STATE_CREATED = 'CREATED';
    public const STATE_BANK_ATTACHED = 'BANK_ATTACHED';
    public const STATE_AWAITING_ESIGN = 'AWAITING_ESIGN';
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_FAILED = 'FAILED';

    /**
     * @var string[]
     */
    public const STATES = [
        self::STATE_NOT_STARTED,
        self::STATE_QUEUED,
        self::STATE_CREATED,
        self::STATE_BANK_ATTACHED,
        self::STATE_AWAITING_ESIGN,
        self::STATE_ACTIVE,
        self::STATE_FAILED,
    ];

    /**
     * Why the last onboarding attempt failed. Drives what happens next:
     *
     * retryable      PayU unreachable or 5xx — state is kept and the cron retries with
     *                backoff; only after the retries run out does the row go FAILED
     * business       PayU refused the data (a 4xx), or our own pre-flight did — retrying
     *                the same data changes nothing, so the row goes FAILED at once
     * configuration  credentials or scope are wrong — nothing per-vendor will fix it
     * orphan         PayU created an account outside our aggregator; it must never be used
     */
    public const ERROR_RETRYABLE = 'retryable';
    public const ERROR_BUSINESS = 'business';
    public const ERROR_CONFIGURATION = 'configuration';
    public const ERROR_ORPHAN = 'orphan';

    /**
     * States the onboarding cron still has work to do in. Everything else is either waiting
     * on a human (AWAITING_ESIGN, FAILED), done (ACTIVE) or not yet approved (NOT_STARTED).
     *
     * @var string[]
     */
    /**
     * How long after a create attempt PayU's listing is trusted to show what it made. Until then an
     * empty parent (Retry) or a missing child (Forget) may just be a create not listed yet.
     */
    public const CREATE_SETTLE_SECONDS = 3600;

    public const WORKABLE_STATES = [self::STATE_QUEUED, self::STATE_CREATED, self::STATE_BANK_ATTACHED];

    /**
     * Columns only the onboarding code may write. The admin edit form posts every column it
     * renders; these are never taken from a post (see SubmerchantItemConvertor).
     *
     * @var string[]
     */
    public const ONBOARDING_COLUMNS = [
        'onboarding_state', 'payu_uuid', 'payu_email', 'last_error', 'error_class', 'attempts',
        'last_attempt_at', 'next_attempt_at', 'locked_until', 'esign_sent_at', 'esign_sent_by', 'activated_at',
    ];

    /**
     * The forward path only. FAILED is reachable from anywhere and is handled separately,
     * as is the FAILED -> QUEUED retry.
     *
     * @var array<string, string[]>
     */
    private const FORWARD = [
        self::STATE_NOT_STARTED => [self::STATE_QUEUED],
        self::STATE_QUEUED => [self::STATE_CREATED],
        self::STATE_CREATED => [self::STATE_BANK_ATTACHED],
        self::STATE_BANK_ATTACHED => [self::STATE_AWAITING_ESIGN],
        self::STATE_AWAITING_ESIGN => [self::STATE_ACTIVE],
        self::STATE_ACTIVE => [],
        self::STATE_FAILED => [self::STATE_QUEUED],
    ];

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SubmerchantResource::class);
    }

    /**
     * @return int
     */
    public function getSellerId(): int
    {
        return (int) $this->getData('seller_id');
    }

    /**
     * Empty string when PayU has not created the account yet. Callers that are about to
     * move money must not rely on this alone — check readiness first.
     *
     * @return string
     */
    public function getMerchantKey(): string
    {
        return (string) $this->getData('payu_merchant_key');
    }

    /**
     * @return bool
     */
    public function hasMerchantKey(): bool
    {
        return $this->getMerchantKey() !== '';
    }

    /**
     * PayU's product_account uuid, needed to address every subsequent PUT/GET.
     *
     * @return string|null
     */
    public function getPayuUuid(): ?string
    {
        $uuid = $this->getData('payu_uuid');
        return $uuid === null || $uuid === '' ? null : (string) $uuid;
    }

    /**
     * @return string|null
     */
    public function getPayuMid(): ?string
    {
        $mid = $this->getData('payu_mid');
        return $mid === null ? null : (string) $mid;
    }

    /**
     * @return string
     */
    public function getKycStatus(): string
    {
        return (string) $this->getData('kyc_status');
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool) (int) $this->getData('is_active');
    }

    /**
     * @return string
     */
    public function getOnboardingState(): string
    {
        $state = (string) $this->getData('onboarding_state');
        return $state === '' ? self::STATE_NOT_STARTED : $state;
    }

    /**
     * @return string|null
     */
    public function getLastError(): ?string
    {
        $error = $this->getData('last_error');
        return $error === null || $error === '' ? null : (string) $error;
    }

    /**
     * @return int
     */
    public function getAttempts(): int
    {
        return (int) $this->getData('attempts');
    }

    /**
     * @return string|null One of the ERROR_* constants, or null when the last attempt succeeded
     */
    public function getErrorClass(): ?string
    {
        $class = $this->getData('error_class');
        return $class === null || $class === '' ? null : (string) $class;
    }

    /**
     * The email a create call was sent with — written before the call, so a retry after a
     * crash can recognise the child PayU made even if the vendor has changed their email.
     *
     * @return string|null
     */
    public function getPayuEmail(): ?string
    {
        $email = $this->getData('payu_email');
        return $email === null || $email === '' ? null : (string) $email;
    }

    /**
     * Whether this row is under onboarding's control (as opposed to a hand-typed mapping or a
     * vendor not yet approved). The admin edit form must not change such a row.
     *
     * @return bool
     */
    public function isManagedByOnboarding(): bool
    {
        return $this->getPayuUuid() !== null || $this->getOnboardingState() !== self::STATE_NOT_STARTED;
    }

    /**
     * @return string|null UTC datetime string, or null when the vendor is due now
     */
    public function getNextAttemptAt(): ?string
    {
        $at = $this->getData('next_attempt_at');
        return $at === null || $at === '' ? null : (string) $at;
    }

    /**
     * A hand-typed mapping from before onboarding existed: it carries a merchant key but
     * PayU never gave us a uuid for it. Onboarding must not touch such a row — creating a
     * child for that vendor would give them a second, undeletable PayU account.
     *
     * @return bool
     */
    public function isLegacyMapping(): bool
    {
        return $this->hasMerchantKey() && $this->getPayuUuid() === null;
    }

    /**
     * Whether the onboarding worker may pick this row up: in a worker-owned state, and not a
     * hand-typed legacy mapping (for which onboarding would create a second account).
     *
     * @return bool
     */
    public function isWorkable(): bool
    {
        return in_array($this->getOnboardingState(), self::WORKABLE_STATES, true) && !$this->isLegacyMapping();
    }

    /**
     * Whether the vendor is sitting in the staff work queue waiting for the Schedule C
     * e-sign email to be triggered from PayU's dashboard.
     *
     * @return bool
     */
    public function isAwaitingEsign(): bool
    {
        return $this->getOnboardingState() === self::STATE_AWAITING_ESIGN;
    }

    /**
     * @return bool
     */
    public function isEsignSent(): bool
    {
        return $this->getData('esign_sent_at') !== null;
    }

    /**
     * Whether $target is a legal next state from where this row is now. FAILED is reachable
     * from every state; everything else follows the forward path.
     *
     * @param string $target
     * @return bool
     */
    public function canTransitionTo(string $target): bool
    {
        if (!in_array($target, self::STATES, true)) {
            return false;
        }
        if ($target === self::STATE_FAILED) {
            return $this->getOnboardingState() !== self::STATE_FAILED;
        }
        return in_array($target, self::FORWARD[$this->getOnboardingState()] ?? [], true);
    }
}
