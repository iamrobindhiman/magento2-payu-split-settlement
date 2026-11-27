<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Reads each seller's shipped-or-cancelled (terminal) status for one order out of
 * Webkul's marketplace_orders (one row per order+seller). A seller is terminal once
 * they have a real shipment OR their portion is cancelled — the ship-based trigger
 * that lets the split fire (matrix §5).
 *
 * Note shipment_id is a NOT-NULL varchar that DEFAULTS to '0' (not empty) until a
 * shipment exists, so "shipped" means shipment_id is neither '' nor '0'. Rows are
 * aggregated per seller (MAX) to stay valid under ONLY_FULL_GROUP_BY and to survive
 * any duplicate (order_id, seller_id) row — that table has no unique key on the pair.
 *
 * Read through our own resource model (guard-compliant getConnection()) rather than
 * injecting ResourceConnection or coupling to Webkul's collections — mirrors
 * SellerEarnings.
 */
class SellerOrderStatus extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('marketplace_orders', 'entity_id');
    }

    /**
     * @param int $orderId Magento order entity id (marketplace_orders.order_id)
     * @return array<int, bool> seller_id => is terminal (shipped or cancelled)
     */
    public function getTerminalFlags(int $orderId): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(
                ['o' => $this->getMainTable()],
                [
                    'seller_id' => 'o.seller_id',
                    'shipped' => new Expression(
                        "MAX(CASE WHEN o.shipment_id IS NOT NULL "
                        . "AND o.shipment_id <> '' AND o.shipment_id <> '0' THEN 1 ELSE 0 END)"
                    ),
                    'canceled' => new Expression('MAX(o.is_canceled)'),
                ]
            )
            ->where('o.order_id = ?', $orderId)
            ->group('o.seller_id');

        $flags = [];
        foreach ($connection->fetchAll($select) as $row) {
            $flags[(int) $row['seller_id']] = ((int) $row['shipped'] === 1) || ((int) $row['canceled'] === 1);
        }

        return $flags;
    }

    /**
     * Whether any seller's portion of the order is cancelled — the cancellation-scope
     * money event that brings a not-yet-split order's split forward (Fact 4).
     *
     * @param int $orderId Magento order entity id (marketplace_orders.order_id)
     * @return bool
     */
    public function hasCanceledSeller(int $orderId): bool
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(['o' => $this->getMainTable()], [new Expression('COUNT(*)')])
            ->where('o.order_id = ?', $orderId)
            ->where('o.is_canceled = ?', 1);

        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * Whether ONE seller's portion of the order is cancelled (marketplace_orders.is_canceled = 1).
     * The release gate (A5c, gate blocker #1) consults this per vendor segment so it never releases
     * a segment that is in fact a refund candidate — keeping release consistent with the refund
     * path, which selects on the very same flag (RefundableSegments). Counted (not row-equality)
     * to stay multi-row-safe: that table has no unique key on (order_id, seller_id).
     *
     * @param int $orderId Magento order entity id (marketplace_orders.order_id)
     * @param int $sellerId Webkul seller id (marketplace_orders.seller_id)
     * @return bool
     */
    public function isSellerCanceled(int $orderId, int $sellerId): bool
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(['o' => $this->getMainTable()], [new Expression('COUNT(*)')])
            ->where('o.order_id = ?', $orderId)
            ->where('o.seller_id = ?', $sellerId)
            ->where('o.is_canceled = ?', 1);

        return (int) $connection->fetchOne($select) > 0;
    }
}
