<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Reads per-item earnings rows (one per marketplace_saleslist line) for one order,
 * with the per-seller tax_to_seller flag attached.
 *
 * Sibling of {@see SellerEarnings}: it reads the SAME source rows, ungrouped, so the
 * sum of a seller's item payouts equals that seller's aggregated segment by
 * construction. getConnection() is inherited from AbstractDb — no ResourceConnection
 * import (the module's CodingStandardsGuardTest forbids it) and no raw SQL literals.
 */
class OrderItemEarnings extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('marketplace_saleslist', 'entity_id');
    }

    /**
     * Per-item rows for one order. Each row carries the vendor's net for the line
     * (actual_seller_amount), the commission, the tax and the tax_to_seller flag —
     * everything the per-item gating rows need.
     *
     * @param int $orderId Magento order entity id (marketplace_saleslist.order_id)
     * @return array<int, array<string, string|null>> Rows: order_item_id, mageproduct_id,
     *         seller_id, qty, item_earnings, commission, tax, tax_to_seller
     */
    public function getItemRows(int $orderId): array
    {
        $connection = $this->getConnection();

        $itemSelect = $connection->select()
            ->from(
                ['s' => $this->getMainTable()],
                [
                    'order_item_id' => 's.order_item_id',
                    'mageproduct_id' => 's.mageproduct_id',
                    'seller_id' => 's.seller_id',
                    'qty' => 's.magequantity',
                    'item_earnings' => 's.actual_seller_amount',
                    'commission' => 's.total_commission',
                    'tax' => 's.total_tax',
                ]
            )
            ->where('s.order_id = ?', $orderId);
        $rows = $connection->fetchAll($itemSelect);

        // tax_to_seller is per-seller (read separately, never joined — see SellerEarnings
        // for why a join to marketplace_orders would fan out the SUM).
        $flagSelect = $connection->select()
            ->from(
                ['o' => $this->getTable('marketplace_orders')],
                [
                    'seller_id' => 'o.seller_id',
                    'tax_to_seller' => new Expression('MAX(o.tax_to_seller)'),
                ]
            )
            ->where('o.order_id = ?', $orderId)
            ->group('o.seller_id');
        $flags = [];
        foreach ($connection->fetchAll($flagSelect) as $flagRow) {
            $flags[(int) $flagRow['seller_id']] = $flagRow['tax_to_seller'];
        }

        foreach ($rows as &$row) {
            $row['tax_to_seller'] = $flags[(int) $row['seller_id']] ?? 0;
        }
        unset($row);

        return $rows;
    }
}
