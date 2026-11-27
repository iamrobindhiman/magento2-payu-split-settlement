<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * PayU's view of one child merchant: who it is, plus a snapshot of where it is.
 *
 * PayU returns ~140 fields per account. This keeps the handful that mean something
 * to us, split by lifetime — the identity here is written once and persisted, while
 * everything on ChildMerchantStatus is re-read on every polling tick.
 *
 * The status getters are delegated rather than removed so callers have one object to
 * ask. Read ChildMerchantStatus before treating any of them as a readiness signal:
 * the obvious readings are all wrong, and each is wrong in the direction that would
 * route money at an account PayU has not activated (defect D-2).
 */
class ChildMerchant
{
    /** PayU echoes this back for a correctly parented child; an orphan says "aggregator". */
    public const MERCHANT_TYPE_CHILD = 'child_aggregator';

    /**
     * @param string $uuid PayU's account uuid — the handle for update and read calls
     * @param string $mid Child MID
     * @param string|null $pgKey The split payee key, at product_account_detail.pg_key
     * @param string|null $merchantType "child_aggregator" when correctly parented
     * @param ChildMerchantStatus $status
     */
    public function __construct(
        private readonly string $uuid,
        private readonly string $mid,
        private readonly ?string $pgKey,
        private readonly ?string $merchantType,
        private readonly ChildMerchantStatus $status
    ) {
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @return string
     */
    public function getMid(): string
    {
        return $this->mid;
    }

    /**
     * @return string|null
     */
    public function getPgKey(): ?string
    {
        return $this->pgKey;
    }

    /**
     * @return string|null
     */
    public function getMerchantType(): ?string
    {
        return $this->merchantType;
    }

    /**
     * @return ChildMerchantStatus
     */
    public function getStatus(): ChildMerchantStatus
    {
        return $this->status;
    }

    /**
     * Whether this account actually sits under our aggregator.
     *
     * PayU accepts a create call with no aggregator_parent_mid, returns 200, and
     * gives back an independent merchant that merely looks like a child. The only
     * evidence in the response is merchant_type: a real child says "child_aggregator",
     * an orphan says "aggregator". The payload builder refuses to send without the
     * parent MID, so this is the belt to that braces — if it is ever false, an
     * account exists at PayU that we must not use and cannot delete.
     *
     * @return bool
     */
    public function isParentedCorrectly(): bool
    {
        return $this->merchantType === self::MERCHANT_TYPE_CHILD;
    }

    /**
     * @return string|null
     */
    public function getKycStatus(): ?string
    {
        return $this->status->getKycStatus();
    }

    /**
     * @return string|null
     */
    public function getDocumentStatus(): ?string
    {
        return $this->status->getDocumentStatus();
    }

    /**
     * @return string|null
     */
    public function getOnboardingStatus(): ?string
    {
        return $this->status->getOnboardingStatus();
    }

    /**
     * @return string|null
     */
    public function getSettlementStatus(): ?string
    {
        return $this->status->getSettlementStatus();
    }

    /**
     * @return bool
     */
    public function isOnboardingCompleted(): bool
    {
        return $this->status->isOnboardingCompleted();
    }

    /**
     * @return bool
     */
    public function isServiceAgreementAccepted(): bool
    {
        return $this->status->isAgreementAccepted();
    }

    /**
     * @return bool
     */
    public function isServiceAgreementEsigned(): bool
    {
        return $this->status->isAgreementEsigned();
    }

    /**
     * @return string|null
     */
    public function getBankVerificationStatus(): ?string
    {
        return $this->status->getBank()->getVerificationStatus();
    }

    /**
     * @return string|null
     */
    public function getPennyDepositStatus(): ?string
    {
        return $this->status->getBank()->getPennyDepositStatus();
    }

    /**
     * @return int
     */
    public function getBankUpdateAttemptCount(): int
    {
        return $this->status->getBank()->getUpdateAttemptCount();
    }

    /**
     * @return int
     */
    public function getBankUpdateAttemptLeft(): int
    {
        return $this->status->getBank()->getUpdateAttemptLeft();
    }

    /**
     * @return bool
     */
    public function hasBankAttached(): bool
    {
        return $this->status->getBank()->isAttached();
    }
}
