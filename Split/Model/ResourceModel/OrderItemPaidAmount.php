<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Reads the customer's PAID amount per order line from Magento's IMMUTABLE
 * sales_order_item totals — base_row_total_incl_tax (what the line cost incl tax) minus
 * base_discount_amount (the row's allocated discount). This is the matrix M-2 source: the
 * customer's prorated paid amount, never the pre-discount list price. The totals are
 * frozen by Magento at order placement, so reading them is settlement-snapshot-safe.
 *
 * getConnection() is inherited from AbstractDb — no ResourceConnection import (the
 * module's CodingStandardsGuardTest forbids it) and no raw SQL literals.
 */
class OrderItemPaidAmount extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('sales_order_item', 'item_id');
    }

    /**
     * Per-line paid components for the given order items.
     *
     * @param int[] $orderItemIds sales_order_item ids
     * @return array<int, array<string, string|null>> Rows: item_id, paid_incl_tax, discount, refunded
     */
    public function getPaidRows(array $orderItemIds): array
    {
        if ($orderItemIds === []) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                ['i' => $this->getMainTable()],
                [
                    'item_id' => 'i.item_id',
                    'paid_incl_tax' => 'i.base_row_total_incl_tax',
                    'discount' => 'i.base_discount_amount',
                    // Anything already refunded for this line via a Magento credit memo must
                    // be netted out so the var8 never double-refunds it (H-5).
                    'refunded' => 'i.base_amount_refunded',
                ]
            )
            ->where('i.item_id IN (?)', $orderItemIds);

        return $connection->fetchAll($select);
    }
}
