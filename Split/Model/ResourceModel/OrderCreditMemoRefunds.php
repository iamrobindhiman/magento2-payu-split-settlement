<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Reads the ORDER-LEVEL out-of-band refund components from Magento credit memos that are NOT
 * attributable to any specific order line — base_adjustment_positive (goodwill / ad-hoc refunds
 * via the Adjustment Refund box) and base_shipping_amount (this memo's shipping refund; the
 * cumulative base_shipping_refunded lives on sales_order, NOT on the credit memo). On a split-managed order the var8
 * split-refund path never writes a credit memo, so any of these is an out-of-band admin refund the
 * split engine did not process — and it is invisible both to the per-item base_amount_refunded read
 * (OrderItemPaidAmount) and to the item-less parent commission segment. The release-freeze gate
 * (item #5, extended by F3) must read it separately so an adjustment-only / shipping-only credit
 * memo cannot let a segment release over money the customer was already refunded.
 *
 * getConnection() from AbstractDb — no ResourceConnection, no raw SQL literals (guard-compliant).
 */
class OrderCreditMemoRefunds extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('sales_creditmemo', 'entity_id');
    }

    /**
     * Per-credit-memo order-level refund components (adjustment_positive + shipping) for an order.
     *
     * @param int $orderId sales_order.entity_id
     * @return array<int, array<string, string|null>> Rows: adjustment_positive, shipping_refunded
     */
    public function getOrderRefundRows(int $orderId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                ['cm' => $this->getMainTable()],
                [
                    // NB: the per-memo shipping refund is base_shipping_amount — sales_creditmemo has
                    // NO base_shipping_refunded (that cumulative column is on sales_order). Verified
                    // vs vendor/magento/module-sales/etc/db_schema.xml.
                    'adjustment_positive' => 'cm.base_adjustment_positive',
                    'shipping_refunded' => 'cm.base_shipping_amount',
                ]
            )
            ->where('cm.order_id = ?', $orderId);

        return $connection->fetchAll($select);
    }
}
