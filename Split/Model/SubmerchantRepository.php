<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;

/**
 * Loads a sub-merchant mapping by seller_id (the natural key) and persists it.
 * Thin wrapper over the resource model; no ResourceConnection (guard-compliant).
 */
class SubmerchantRepository implements SubmerchantRepositoryInterface
{
    /**
     * @param SubmerchantResource $resource
     * @param SubmerchantFactory $submerchantFactory
     */
    public function __construct(
        private readonly SubmerchantResource $resource,
        private readonly SubmerchantFactory $submerchantFactory
    ) {
    }

    /**
     * @param int $sellerId
     * @return Submerchant|null
     */
    public function getBySellerId(int $sellerId): ?Submerchant
    {
        $model = $this->submerchantFactory->create();
        $this->resource->load($model, $sellerId, 'seller_id');
        return $model->getId() ? $model : null;
    }

    /**
     * @param Submerchant $submerchant
     * @return Submerchant
     */
    public function save(Submerchant $submerchant): Submerchant
    {
        $this->resource->save($submerchant);
        return $submerchant;
    }
}
