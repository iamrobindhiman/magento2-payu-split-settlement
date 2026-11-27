<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * A moment-in-time reading of where PayU thinks a child merchant is.
 *
 * Separate from the identity on ChildMerchant because the two have different
 * lifetimes: uuid, MID and pg_key are written once and persisted; everything here is
 * re-read on every polling tick and never trusted between ticks.
 *
 * READ THIS BEFORE USING ANY FIELD BELOW AS A READINESS SIGNAL. Measured on a real
 * UAT child, 2026-09-10, at creation — before a bank account, before Schedule C,
 * before PayU had looked at the account:
 *
 *     onboarding_completed  true      <- already true, seconds after creation
 *     document_status       "Docs Approved"
 *     settlement_status     "Active"
 *     kyc_status.status     "LOCKED"
 *     is_service_agreement_esigned  false
 *
 * and the same account two minutes later:
 *
 *     document_status       "Pending"    <- it went BACKWARDS
 *     onboarding_status     "Documentation & Website Verification in progress"
 *
 * So `onboardingCompleted` is true on an account that demonstrably cannot be paid,
 * `documentStatus` regresses, and `settlementStatus` is a risk-hold flag rather than
 * an onboarding field at all. Each of those was, at some point, a candidate for
 * "the activation signal". None of them is one.
 *
 * The best remaining lead is agreementEsigned — Schedule C — which is the one field
 * that was false and is expected to become true only through a real human action.
 * That is a lead, not a verdict, and it is deliberately NOT decided here:
 * SubmerchantReadiness is the single place allowed to answer "can PayU pay this
 * vendor yet?", so that when the production test run tells us the truth there is
 * exactly one file to correct (D-2).
 */
class ChildMerchantStatus
{
    /**
     * @param string|null $kycStatus kyc_status.status, e.g. "LOCKED"
     * @param string|null $documentStatus Regresses after creation — not a progress signal
     * @param string|null $onboardingStatus Free text, rewords between calls
     * @param string|null $settlementStatus A RISK-HOLD flag, not an onboarding field
     * @param bool $onboardingCompleted True from creation onward — not an activation signal
     * @param bool $agreementAccepted Schedule C accepted
     * @param bool $agreementEsigned Schedule C e-signed — the best current lead
     * @param ChildMerchantBank $bank
     */
    public function __construct(
        private readonly ?string $kycStatus,
        private readonly ?string $documentStatus,
        private readonly ?string $onboardingStatus,
        private readonly ?string $settlementStatus,
        private readonly bool $onboardingCompleted,
        private readonly bool $agreementAccepted,
        private readonly bool $agreementEsigned,
        private readonly ChildMerchantBank $bank
    ) {
    }

    /**
     * @return string|null
     */
    public function getKycStatus(): ?string
    {
        return $this->kycStatus;
    }

    /**
     * @return string|null
     */
    public function getDocumentStatus(): ?string
    {
        return $this->documentStatus;
    }

    /**
     * @return string|null
     */
    public function getOnboardingStatus(): ?string
    {
        return $this->onboardingStatus;
    }

    /**
     * @return string|null
     */
    public function getSettlementStatus(): ?string
    {
        return $this->settlementStatus;
    }

    /**
     * @return bool
     */
    public function isOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    /**
     * @return bool
     */
    public function isAgreementAccepted(): bool
    {
        return $this->agreementAccepted;
    }

    /**
     * @return bool
     */
    public function isAgreementEsigned(): bool
    {
        return $this->agreementEsigned;
    }

    /**
     * @return ChildMerchantBank
     */
    public function getBank(): ChildMerchantBank
    {
        return $this->bank;
    }
}
