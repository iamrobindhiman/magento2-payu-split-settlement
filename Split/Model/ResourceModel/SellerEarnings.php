<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Reads per-seller earnings for one order out of Webkul's marketplace_saleslist,
 * with the tax_to_seller flag joined from marketplace_orders.
 *
 * We read Webkul's stable tables through our own resource model rather than
 * injecting ResourceConnection (the module's CodingStandardsGuardTest forbids the
 * direct import) or coupling to Webkul's collection classes. getConnection() is
 * inherited from AbstractDb.
 */
class SellerEarnings extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('marketplace_saleslist', 'entity_id');
    }

    /**
     * Aggregated earnings per seller for a single order.
     *
     * Earnings are summed from marketplace_saleslist ALONE (one row per item) and
     * the tax_to_seller flag is read from marketplace_orders in a SEPARATE query.
     * We deliberately do NOT join the two tables: marketplace_orders has no unique
     * key on (order_id, seller_id), so a join would fan out and double SUM() if a
     * duplicate order row ever exists. Both queries aggregate every non-grouped
     * column (MAX on the flag) to stay valid under MySQL ONLY_FULL_GROUP_BY.
     *
     * @param int $orderId Magento order entity id (marketplace_saleslist.order_id)
     * @return array<int, array<string, string|null>> Rows: seller_id, item_earnings, tax, tax_to_seller
     */
    public function getSellerTotals(int $orderId): array
    {
        $connection = $this->getConnection();

        $earningsSelect = $connection->select()
            ->from(
                ['s' => $this->getMainTable()],
                [
                    'seller_id' => 's.seller_id',
                    'item_earnings' => new Expression('SUM(s.actual_seller_amount)'),
                    'tax' => new Expression('SUM(s.total_tax)'),
                ]
            )
            ->where('s.order_id = ?', $orderId)
            ->group('s.seller_id');
        $earnings = $connection->fetchAll($earningsSelect);

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

        foreach ($earnings as &$row) {
            $row['tax_to_seller'] = $flags[(int) $row['seller_id']] ?? 0;
        }
        unset($row);

        return $earnings;
    }
}
