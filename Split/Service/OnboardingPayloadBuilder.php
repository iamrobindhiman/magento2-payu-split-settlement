<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\OnboardingPayloadException;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingConfig;

/**
 * Builds the two onboarding request bodies, and refuses to build one that would do
 * something irreversible.
 *
 * Pure: no network, no persistence. That is what makes T-04 and T-06 unit-testable.
 */
class OnboardingPayloadBuilder
{
    /** PayU's own product name for the gateway account we create under. */
    private const PRODUCT = 'PayUbiz';

    /**
     * Posted as "aggregator" — PayU echoes back "child_aggregator" once it has
     * resolved the parent. Posting "child_aggregator" is not the same thing and is
     * not what the API asks for.
     */
    private const MERCHANT_TYPE = 'aggregator';

    private const IFSC_PATTERN = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    /**
     * @param OnboardingConfig $config
     */
    public function __construct(
        private readonly OnboardingConfig $config
    ) {
    }

    /**
     * The create-child-merchant body.
     *
     * @param ChildMerchantDraft $draft
     * @return array<string, mixed>
     * @throws OnboardingPayloadException When the payload would create an orphan
     */
    public function buildCreate(ChildMerchantDraft $draft): array
    {
        $parentMid = $this->config->getParentMid();

        // T-04. This is the single most important line in the file. Without the parent
        // MID PayU returns 200 and creates a merchant OUTSIDE our aggregator — an
        // account we cannot read, edit, split to, or delete. It has to fail here,
        // because by the time PayU has answered it is already too late.
        if ($parentMid === '') {
            throw new OnboardingPayloadException(
                'Refusing to create a PayU child merchant with no aggregator_parent_mid: '
                . 'PayU would answer 200 and silently create an orphan account outside the aggregator.'
            );
        }

        if ($draft->getEmail() === '' && $draft->getMobile() === '') {
            throw new OnboardingPayloadException(
                'Refusing to create a PayU child merchant with neither email nor mobile: '
                . 'PayU identifies and de-duplicates accounts on those two fields.'
            );
        }

        if ($draft->getBusinessEntityId() <= 0) {
            throw new OnboardingPayloadException(
                'Refusing to create a PayU child merchant without a business entity type.'
            );
        }

        $account = [
            'product' => self::PRODUCT,
            'name' => $draft->getName(),
            'email' => $draft->getEmail(),
            'mobile' => $draft->getMobile(),
            'aggregator_parent_mid' => $parentMid,
            'merchant_type' => self::MERCHANT_TYPE,
            'business_entity_id' => $draft->getBusinessEntityId(),
            'business_name' => $draft->getBusinessName(),
            'monthly_expected_volume' => $draft->getMonthlyExpectedVolume(),
        ];

        if ($draft->getPanNumber() !== null) {
            $account['pancard_number'] = $draft->getPanNumber();
        }
        if ($draft->getPanName() !== null) {
            $account['pancard_name'] = $draft->getPanName();
        }

        return ['product_account' => $account];
    }

    /**
     * The attach-bank body.
     *
     * PayU accepts the settlement account ONCE; a second submission answers
     * 422 "Bank details already submitted" and burns one of eleven attempts (N-4).
     * So the values are validated hard here rather than discovered to be wrong by
     * spending the one attempt on them.
     *
     * @param string $accountNumber Plaintext, decrypted by the caller moments before
     * @param string $ifsc
     * @param string $holderName
     * @return array<string, mixed>
     * @throws OnboardingPayloadException When a value would waste the single attempt
     */
    public function buildBankDetail(string $accountNumber, string $ifsc, string $holderName): array
    {
        $accountNumber = trim($accountNumber);
        $ifsc = strtoupper(trim($ifsc));
        $holderName = trim($holderName);

        if (!preg_match('/^\d{9,18}$/', $accountNumber)) {
            throw new OnboardingPayloadException(
                'Refusing to send a settlement account number that is not 9-18 digits. '
                . 'PayU allows one bank submission per child merchant; a rejected one is spent.'
            );
        }

        if (!preg_match(self::IFSC_PATTERN, $ifsc)) {
            throw new OnboardingPayloadException(
                'Refusing to send a malformed IFSC code. '
                . 'PayU allows one bank submission per child merchant; a rejected one is spent.'
            );
        }

        if ($holderName === '') {
            throw new OnboardingPayloadException(
                'Refusing to send a settlement account with no holder name.'
            );
        }

        return ['product_account' => ['bank_detail' => [
            'bank_account_number' => $accountNumber,
            'ifsc_code' => $ifsc,
            'holder_name' => $holderName,
        ]]];
    }
}
