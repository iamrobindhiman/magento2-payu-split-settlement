<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitSegmentItem as SplitSegmentItemModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Resource model for rkd_payu_segment_item (per-item delivery/return gating).
 */
class SplitSegmentItem extends AbstractDb
{
    /**
     * @param Context $context
     * @param DateTime $dateTime
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly DateTime $dateTime,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_segment_item', 'item_row_id');
    }

    /**
     * Set return_state on the segment's items that match the given order_item_ids (gate blocker #1,
     * part c). SegmentEligibility blocks release while any item is RETURN_OPEN; this is its producer.
     * Scoped to one segment so a shared order_item_id can never bleed across vendors.
     *
     * Guard-compliant: getConnection()->update(), no raw SQL literal, no ResourceConnection.
     *
     * @param int $segmentId
     * @param int[] $orderItemIds
     * @param string $returnState One of SplitSegmentItem::RETURN_*
     * @return int Rows affected
     */
    public function markReturnState(int $segmentId, array $orderItemIds, string $returnState): int
    {
        if ($orderItemIds === []) {
            return 0;
        }

        $connection = $this->getConnection();

        // Stamp return_opened_at when a return OPENS (the #9 strand-alarm age anchor); clear it on
        // any non-open transition (rejected/refunded/replaced) so a re-opened return re-stamps fresh.
        $openedAt = $returnState === SplitSegmentItemModel::RETURN_OPEN ? $this->dateTime->gmtDate() : null;

        return (int) $connection->update(
            $this->getMainTable(),
            [
                'return_state' => $returnState,
                'return_opened_at' => $openedAt,
            ],
            [
                'segment_id = ?' => $segmentId,
                'order_item_id IN (?)' => $orderItemIds,
            ]
        );
    }
}
