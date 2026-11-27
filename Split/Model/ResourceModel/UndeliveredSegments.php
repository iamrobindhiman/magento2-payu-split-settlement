<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Selects the vendor segments the delivery poll still needs to check: their order's split is
 * DONE, the segment is live (not cancelled/refunded/released), and at least one of its item
 * rows has no delivered_at yet. Returns (segment_id, order_id, seller_id) — the keys the poll
 * needs to ask Shiprocket about that seller's shipment and stamp the segment.
 *
 * Bounded by design: only open-split, live, undelivered vendor segments — never the whole
 * shipment history. Guard-compliant: getConnection()->select(), no raw SQL literals, no
 * ResourceConnection.
 */
class UndeliveredSegments extends AbstractDb
{
    /**
     * Segment states that are no longer awaiting delivery (cancelled, refunded, in/post
     * release) — excluded so the poll never re-checks a vendor that will never deliver.
     */
    private const NOT_AWAITING_DELIVERY = [
        SplitSegment::STATE_CANCELLED,
        SplitSegment::STATE_REFUND_PENDING,
        SplitSegment::STATE_PARTIAL_REFUNDED,
        SplitSegment::STATE_REFUNDED,
        SplitSegment::STATE_RELEASE_REQUESTED,
        SplitSegment::STATE_RELEASED,
        SplitSegment::STATE_WRITTEN_OFF,
        SplitSegment::STATE_NOT_IN_SPLIT,
    ];

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_split_segment', 'segment_id');
    }

    /**
     * @return array<int, array<string, string>> Rows: segment_id, order_id, seller_id
     */
    public function getUndeliveredRows(): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(
                ['seg' => $this->getMainTable()],
                ['segment_id' => 'seg.segment_id', 'order_id' => 'seg.order_id', 'seller_id' => 'seg.seller_id']
            )
            ->join(
                ['sp' => $this->getTable('rkd_payu_split')],
                'seg.split_id = sp.entity_id',
                []
            )
            ->join(
                ['it' => $this->getTable('rkd_payu_segment_item')],
                'it.segment_id = seg.segment_id',
                []
            )
            ->where('sp.split_state = ?', SplitOrder::STATE_DONE)
            ->where('seg.segment_type = ?', SplitSegment::TYPE_VENDOR)
            ->where('seg.state NOT IN (?)', self::NOT_AWAITING_DELIVERY)
            ->where('it.delivered_at IS NULL')
            ->group(['seg.segment_id', 'seg.order_id', 'seg.seller_id']);

        return $connection->fetchAll($select);
    }
}
