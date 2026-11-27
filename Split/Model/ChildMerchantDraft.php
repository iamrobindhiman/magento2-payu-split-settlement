<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The vendor details a child merchant is created from.
 *
 * A plain carrier, deliberately holding no vendor-table knowledge: mapping
 * marketplace_userdata onto these fields belongs to S-3, so that when the vendor
 * schema changes the payload builder and its tests do not.
 *
 * Nothing here is a secret. PAN is identity data and is carried, but the settlement
 * bank account is NOT — that travels separately, decrypted in memory at the moment
 * of the bank call and never held on an object that might be logged (R-2, N-5).
 */
class ChildMerchantDraft
{
    /**
     * @param string $name Contact/display name
     * @param string $email Vendor's email — PayU dedupes on this
     * @param string $mobile Vendor's mobile — PayU dedupes on this too
     * @param string $businessName Legal/trade name
     * @param int $businessEntityId PayU's entity type id (2 = Sole Proprietorship)
     * @param string|null $panNumber
     * @param string|null $panName Name as printed on the PAN card
     * @param int $monthlyExpectedVolume Rupees; PayU requires a figure
     */
    public function __construct(
        private readonly string $name,
        private readonly string $email,
        private readonly string $mobile,
        private readonly string $businessName,
        private readonly int $businessEntityId,
        private readonly ?string $panNumber = null,
        private readonly ?string $panName = null,
        private readonly int $monthlyExpectedVolume = 0
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return trim($this->name);
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return trim($this->email);
    }

    /**
     * @return string
     */
    public function getMobile(): string
    {
        return trim($this->mobile);
    }

    /**
     * @return string
     */
    public function getBusinessName(): string
    {
        return trim($this->businessName);
    }

    /**
     * @return int
     */
    public function getBusinessEntityId(): int
    {
        return $this->businessEntityId;
    }

    /**
     * @return string|null
     */
    public function getPanNumber(): ?string
    {
        $pan = $this->panNumber === null ? '' : trim($this->panNumber);
        return $pan === '' ? null : strtoupper($pan);
    }

    /**
     * @return string|null
     */
    public function getPanName(): ?string
    {
        $name = $this->panName === null ? '' : trim($this->panName);
        return $name === '' ? null : $name;
    }

    /**
     * @return int
     */
    public function getMonthlyExpectedVolume(): int
    {
        return $this->monthlyExpectedVolume;
    }
}
