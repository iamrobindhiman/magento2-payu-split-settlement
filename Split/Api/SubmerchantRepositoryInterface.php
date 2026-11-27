<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Api;

use RKD\PayuSplit\Split\Model\Submerchant;

/**
 * Reads/writes the seller_id -> PayU child sub-merchant mapping.
 * Internal contract (not a webapi); returns the concrete model by design.
 */
interface SubmerchantRepositoryInterface
{
    /**
     * @param int $sellerId
     * @return Submerchant|null Null when the seller has no mapping row.
     */
    public function getBySellerId(int $sellerId): ?Submerchant;

    /**
     * @param string $uuid PayU product_account uuid
     * @return Submerchant|null Null when no seller is mapped to that PayU account.
     */
    public function getByPayuUuid(string $uuid): ?Submerchant;

    /**
     * @param int $entityId
     * @return Submerchant|null
     */
    public function getById(int $entityId): ?Submerchant;

    /**
     * The row that already holds this PayU account by MID or merchant key, if any — used to
     * stop one PayU account being attached to two sellers.
     *
     * @param string $mid
     * @param string|null $merchantKey
     * @return Submerchant|null
     */
    public function getByPayuMidOrKey(string $mid, ?string $merchantKey): ?Submerchant;

    /**
     * @param Submerchant $submerchant
     * @return Submerchant
     */
    public function save(Submerchant $submerchant): Submerchant;
}
