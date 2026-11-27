<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

/**
 * Maps the legal type a vendor chose at registration onto PayU's business_entity_id, and
 * says which PAN category letters fit that legal type.
 *
 * Pure. Two reasons it has to be right before anything is sent:
 *   - a child merchant cannot be deleted, so one created with the wrong entity type is a
 *     permanent wrong record at PayU;
 *   - PayU verifies the PAN against the entity type, and the 4th character of a PAN IS the
 *     holder's category (P person, F firm/LLP, C company, T trust, A association).
 *
 * The entity ids are PayU's v3 table (docs/payu/kb/onboarding-api.md). Sole Proprietorship = 2
 * is proven on UAT (the S-2 fixtures); the others are read from PayU's docs and are the first
 * thing to check if PayU rejects a non-proprietorship create.
 *
 * The PAN letters mirror Fab\MarketplaceRegister\Model\Validator\TaxIdConsistency, which warns
 * the vendor at registration. Here the same mismatch BLOCKS, because here it costs an
 * undeletable account rather than a warning.
 */
class BusinessEntityResolver
{
    /**
     * legal_type => [PayU business_entity_id, PAN 4th letters that fit it]
     *
     * @var array<string, array{0: int, 1: string[]}>
     */
    private const MAP = [
        'proprietorship' => [2, ['P']],
        'partnership' => [3, ['F']],
        'llp' => [6, ['F']],
        'pvt_ltd' => [4, ['C']],
        'public_ltd' => [5, ['C']],
        'trust' => [7, ['T']],
        'society' => [8, ['A', 'T']],
    ];

    /**
     * @param string|null $legalType
     * @return int|null Null when the legal type is missing or not one we can map
     */
    public function entityId(?string $legalType): ?int
    {
        return self::MAP[$this->normalise($legalType)][0] ?? null;
    }

    /**
     * Whether the PAN's category letter fits the legal type. False when either is unusable.
     *
     * @param string|null $pan
     * @param string|null $legalType
     * @return bool
     */
    public function panFitsLegalType(?string $pan, ?string $legalType): bool
    {
        $letters = self::MAP[$this->normalise($legalType)][1] ?? null;
        $pan = strtoupper(trim((string)$pan));
        if ($letters === null || strlen($pan) !== 10) {
            return false;
        }
        return in_array($pan[3], $letters, true);
    }

    /**
     * @param string|null $legalType
     * @return string[] The PAN letters expected for this legal type, empty when unmapped
     */
    public function expectedPanLetters(?string $legalType): array
    {
        return self::MAP[$this->normalise($legalType)][1] ?? [];
    }

    /**
     * @param string|null $legalType
     * @return string
     */
    private function normalise(?string $legalType): string
    {
        return strtolower(trim((string)$legalType));
    }
}
