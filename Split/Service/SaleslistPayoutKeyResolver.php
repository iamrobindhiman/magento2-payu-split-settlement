<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory as SaleslistCollectionFactory;

/**
 * A5c-4b — resolves the Webkul saleslist row ids the admin "Pay Seller" actions submit
 * (Payseller's autoorderid, MassPayseller's wksellerorderids CSV) into the (order_id, seller_id)
 * keys the PayuReleaseGuard checks. This is the suppressor's single dependency on Webkul's own
 * tables — kept in one small reader so neither plugin has to know the saleslist schema, and the
 * guard stays pure PayU-domain.
 *
 * Guard-compliant: no ResourceConnection, no raw SQL — a plain collection read. Non-numeric and
 * non-positive ids (a CSV can carry blanks/zeroes) are dropped before the query; an empty result
 * skips the query entirely.
 */
class SaleslistPayoutKeyResolver
{
    /**
     * @param SaleslistCollectionFactory $saleslistCollectionFactory
     */
    public function __construct(
        private readonly SaleslistCollectionFactory $saleslistCollectionFactory
    ) {
    }

    /**
     * @param int[] $saleslistEntityIds
     * @return array<int, array{order_id: int, seller_id: int}> One entry per resolved saleslist row
     */
    public function resolve(array $saleslistEntityIds): array
    {
        $ids = array_values(array_filter(
            array_map('intval', $saleslistEntityIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($ids === []) {
            return [];
        }

        $collection = $this->saleslistCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);

        $keys = [];
        foreach ($collection->getItems() as $row) {
            $keys[] = [
                'order_id' => (int) $row->getOrderId(),
                'seller_id' => (int) $row->getSellerId(),
            ];
        }
        return $keys;
    }
}
