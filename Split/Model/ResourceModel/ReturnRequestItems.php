<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Reads the order_item_ids belonging to one Fab_Returns request (fab_return_request_item).
 *
 * Deliberately couples to Fab_Returns by TABLE NAME only — not by class — exactly as this module
 * already reads Webkul's marketplace_orders directly (SellerOrderStatus, RefundableSegments). That
 * keeps RKD_PayuSplit installable without Fab_Returns: the only caller is the fab_returns_status_changed
 * observer, which can fire only when Fab_Returns is present (and so its table exists).
 *
 * Guard-compliant: getConnection()->select(), no raw SQL literals, no ResourceConnection.
 */
class ReturnRequestItems extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('fab_return_request_item', 'entity_id');
    }

    /**
     * @param int $requestId fab_return_request_item.request_id
     * @return int[] The sales_order_item ids requested for return on this request
     */
    public function getOrderItemIds(int $requestId): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(['ri' => $this->getMainTable()], ['order_item_id'])
            ->where('ri.request_id = ?', $requestId);

        return array_map('intval', $connection->fetchCol($select));
    }
}
