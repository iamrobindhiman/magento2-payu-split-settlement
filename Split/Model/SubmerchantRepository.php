<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;

/**
 * Loads a sub-merchant mapping by seller_id (the natural key) and persists it.
 * Thin wrapper over the resource model; no ResourceConnection (guard-compliant).
 */
class SubmerchantRepository implements SubmerchantRepositoryInterface
{
    /**
     * @param SubmerchantResource $resource
     * @param SubmerchantFactory $submerchantFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly SubmerchantResource $resource,
        private readonly SubmerchantFactory $submerchantFactory,
        private readonly CollectionFactory $collectionFactory
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
     * @param string $uuid
     * @return Submerchant|null
     */
    public function getByPayuUuid(string $uuid): ?Submerchant
    {
        if ($uuid === '') {
            return null;
        }
        $model = $this->submerchantFactory->create();
        $this->resource->load($model, $uuid, 'payu_uuid');
        return $model->getId() ? $model : null;
    }

    /**
     * @param int $entityId
     * @return Submerchant|null
     */
    public function getById(int $entityId): ?Submerchant
    {
        if ($entityId <= 0) {
            return null;
        }
        $model = $this->submerchantFactory->create();
        $this->resource->load($model, $entityId);
        return $model->getId() ? $model : null;
    }

    /**
     * @param string $mid
     * @param string|null $merchantKey
     * @return Submerchant|null
     */
    public function getByPayuMidOrKey(string $mid, ?string $merchantKey): ?Submerchant
    {
        $conditions = [];
        $values = [];
        if ($mid !== '') {
            $conditions[] = 'payu_mid';
            $values[] = ['eq' => $mid];
        }
        if ($merchantKey !== null && $merchantKey !== '') {
            $conditions[] = 'payu_merchant_key';
            $values[] = ['eq' => $merchantKey];
        }
        if ($conditions === []) {
            return null;
        }
        /** @var Submerchant $row */
        $row = $this->collectionFactory->create()
            ->addFieldToFilter($conditions, $values)
            ->setPageSize(1)
            ->getFirstItem();
        return $row->getId() ? $row : null;
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
