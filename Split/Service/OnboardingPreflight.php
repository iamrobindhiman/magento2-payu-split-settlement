<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\SettlementBankDetail;

/**
 * Everything that can be known to be wrong about a vendor BEFORE PayU is called.
 *
 * Runs before the create call, and covers the bank details too, because the two PayU calls
 * are each irreversible in their own way: a child merchant cannot be deleted, and the bank
 * detail is accepted once. A vendor whose bank record is unusable must not get a child at
 * all — it would sit at PayU with no way to ever be paid.
 *
 * Pure. Messages name the field and the rule, never the value: they are stored in
 * rkd_payu_submerchant.last_error and shown to staff (N-5).
 */
class OnboardingPreflight
{
    private const PAN_PATTERN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';
    private const MOBILE_PATTERN = '/^[6-9]\d{9}$/';
    private const ACCOUNT_PATTERN = '/^\d{9,18}$/';
    private const IFSC_PATTERN = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    /**
     * @param BusinessEntityResolver $entityResolver
     */
    public function __construct(
        private readonly BusinessEntityResolver $entityResolver
    ) {
    }

    /**
     * @param ChildMerchantDraft $draft
     * @param string|null $legalType
     * @param SettlementBankDetail $bank
     * @return string[] Problems found; empty when the vendor may be sent to PayU
     */
    public function check(ChildMerchantDraft $draft, ?string $legalType, SettlementBankDetail $bank): array
    {
        return array_merge($this->checkIdentity($draft, $legalType), $this->checkBank($bank));
    }

    /**
     * @param ChildMerchantDraft $draft
     * @param string|null $legalType
     * @return string[]
     */
    public function checkIdentity(ChildMerchantDraft $draft, ?string $legalType): array
    {
        return array_merge($this->checkContact($draft), $this->checkTaxIdentity($draft, $legalType));
    }

    /**
     * @param ChildMerchantDraft $draft
     * @return string[]
     */
    private function checkContact(ChildMerchantDraft $draft): array
    {
        $problems = [];
        if ($draft->getName() === '') {
            $problems[] = 'Contact name is missing.';
        }
        if ($draft->getBusinessName() === '') {
            $problems[] = 'Business name is missing.';
        }
        if (filter_var($draft->getEmail(), FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'Email is missing or invalid.';
        }
        if (!preg_match(self::MOBILE_PATTERN, $draft->getMobile())) {
            $problems[] = 'Mobile is not a 10-digit Indian mobile number.';
        }
        return $problems;
    }

    /**
     * Legal type, PAN and the fit between them.
     *
     * @param ChildMerchantDraft $draft
     * @param string|null $legalType
     * @return string[]
     */
    private function checkTaxIdentity(ChildMerchantDraft $draft, ?string $legalType): array
    {
        $problems = [];
        $mapped = $this->entityResolver->entityId($legalType) !== null;
        if (!$mapped) {
            $problems[] = 'Legal type is missing or not one PayU can be told about.';
        }

        $pan = (string)$draft->getPanNumber();
        if (!preg_match(self::PAN_PATTERN, $pan)) {
            $problems[] = 'PAN is missing, unreadable or not in the ABCDE1234F format.';
        } elseif ($mapped && !$this->entityResolver->panFitsLegalType($pan, $legalType)) {
            $problems[] = sprintf(
                'PAN category letter "%s" does not fit legal type "%s" (expected %s).',
                $pan[3],
                (string)$legalType,
                implode(' or ', $this->entityResolver->expectedPanLetters($legalType))
            );
        }

        if ($draft->getPanName() === null) {
            $problems[] = 'Name as per PAN is missing.';
        }
        return $problems;
    }

    /**
     * @param SettlementBankDetail $bank
     * @return string[]
     */
    public function checkBank(SettlementBankDetail $bank): array
    {
        $problems = [];
        if (!preg_match(self::ACCOUNT_PATTERN, $bank->getAccountNumber())) {
            $problems[] = 'Settlement account number is missing, unreadable or not 9-18 digits.';
        }
        if (!preg_match(self::IFSC_PATTERN, $bank->getIfsc())) {
            $problems[] = 'IFSC is missing or malformed.';
        }
        if ($bank->getHolderName() === '') {
            $problems[] = 'Bank account holder name is missing.';
        }
        return $problems;
    }
}
