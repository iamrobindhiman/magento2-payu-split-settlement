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
     * @param Submerchant $submerchant
     * @return Submerchant
     */
    public function save(Submerchant $submerchant): Submerchant;
}
