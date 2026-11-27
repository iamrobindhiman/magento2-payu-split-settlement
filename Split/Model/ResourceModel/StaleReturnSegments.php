<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Selects PayU-split vendor segments stuck with an OPEN return past a cutoff (#9): an item is
 * RETURN_OPEN, its return_opened_at is older than the cutoff, the split is DONE, and the segment is
 * still non-terminal. These are approved/in-progress returns whose refund never executed
 * (returns-refund is deferred to Fab_Returns v2) — the strand-alarm scan surfaces them so a blocked
 * release is never a SILENT strand (the RBI "never strand unalarmed" rule).
 *
 * Guard-compliant: getConnection()->select(), no raw SQL literals, no ResourceConnection.
 */
class StaleReturnSegments extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_segment_item', 'item_row_id');
    }

    /**
     * @param string $cutoffGmt Items whose return opened strictly before this 'Y-m-d H:i:s' are stale
     * @return array<int, array<string, string|null>> Rows: segment_id, order_id, seller_id, opened_at
     */
    public function getStaleOpenReturnSegments(string $cutoffGmt): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(
                ['it' => $this->getMainTable()],
                ['segment_id' => 'it.segment_id', 'opened_at' => new Expression('MIN(it.return_opened_at)')]
            )
            ->join(
                ['seg' => $this->getTable('rkd_payu_split_segment')],
                'seg.segment_id = it.segment_id',
                ['order_id' => 'seg.order_id', 'seller_id' => 'seg.seller_id']
            )
            ->join(
                ['sp' => $this->getTable('rkd_payu_split')],
                'sp.entity_id = seg.split_id',
                []
            )
            ->where('it.return_state = ?', SplitSegmentItem::RETURN_OPEN)
            ->where('it.return_opened_at IS NOT NULL')
            ->where('it.return_opened_at < ?', $cutoffGmt)
            ->where('sp.split_state = ?', SplitOrder::STATE_DONE)
            ->where('seg.segment_type = ?', SplitSegment::TYPE_VENDOR)
            ->where('seg.state NOT IN (?)', SplitSegment::TERMINAL_STATES)
            ->group('it.segment_id');

        return $connection->fetchAll($select);
    }
}
