<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\Submerchant;

/**
 * The single answer to "can PayU pay this vendor yet?".
 *
 * Every caller — the sell-gate, the split path, the admin grid, the vendor's own
 * dashboard — asks this class and nothing else. That is the whole point: we do not
 * yet know for certain which PayU field flips when a sub-merchant is genuinely
 * activated, because we have never seen one activate. When the production test run
 * tells us, this is the only file that changes.
 *
 * What we DO know, from live UAT calls on 2026-09-09:
 *   - A freshly created sub-merchant returns settlement_status "Active" while
 *     kyc_status is "LOCKED" and is_service_agreement_esigned is false. So
 *     settlement_status alone means nothing.
 *   - Existence of a row means nothing either; the row is written at approval time,
 *     before PayU has been called at all.
 *
 * This class answers for PayU only. Shiprocket readiness is a separate signal and
 * the two are combined by the sell-gate, so neither third party knows about the other.
 */
class SubmerchantReadiness
{
    /**
     * @param SubmerchantRepositoryInterface $submerchantRepository
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $submerchantRepository
    ) {
    }

    /**
     * Whether this vendor's PayU side is complete enough to receive a split.
     *
     * Conservative by design: anything short of a fully activated account with a usable
     * merchant key answers false. A false negative delays a vendor; a false positive
     * sends real money at an account that cannot receive it.
     *
     * @param int $sellerId
     * @return bool
     */
    public function canSell(int $sellerId): bool
    {
        $submerchant = $this->submerchantRepository->getBySellerId($sellerId);
        if ($submerchant === null) {
            return false;
        }
        return $this->isReady($submerchant);
    }

    /**
     * Same rule as canSell(), for callers that already hold the row and should not
     * pay for a second lookup.
     *
     * @param Submerchant $submerchant
     * @return bool
     */
    public function isReady(Submerchant $submerchant): bool
    {
        return $submerchant->getOnboardingState() === Submerchant::STATE_ACTIVE
            && $submerchant->isActive()
            && $submerchant->hasMerchantKey();
    }

    /**
     * Why this vendor cannot sell yet, in words a member of staff can act on. Returns
     * null when the vendor IS ready.
     *
     * @param int $sellerId
     * @return string|null
     */
    public function explain(int $sellerId): ?string
    {
        $submerchant = $this->submerchantRepository->getBySellerId($sellerId);
        if ($submerchant === null) {
            return 'No PayU sub-merchant record exists for this vendor yet.';
        }
        if ($this->isReady($submerchant)) {
            return null;
        }

        return match ($submerchant->getOnboardingState()) {
            Submerchant::STATE_NOT_STARTED => 'Vendor has not been approved yet.',
            Submerchant::STATE_QUEUED => 'Waiting to be created at PayU.',
            Submerchant::STATE_CREATED => 'Created at PayU; settlement bank account not attached yet.',
            Submerchant::STATE_BANK_ATTACHED => 'Bank attached; PayU is still verifying it.',
            Submerchant::STATE_AWAITING_ESIGN => $submerchant->isEsignSent()
                ? 'Schedule C e-sign email sent — waiting for the vendor to sign and for PayU to activate.'
                : 'Waiting for a member of staff to trigger the Schedule C e-sign in PayU\'s dashboard.',
            Submerchant::STATE_FAILED => 'Onboarding failed: '
                . ($submerchant->getLastError() ?? 'reason not recorded') . '.',
            default => 'PayU onboarding is not complete.',
        };
    }
}
