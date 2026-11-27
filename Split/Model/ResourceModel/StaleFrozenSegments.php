<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Selects PayU-split segments carrying a FROZEN hold older than a cutoff (gate item #11). A
 * TYPE_FROZEN hold is placed by the release readback mismatch (INV-18), the O-13 parent child-key
 * gap, the out-of-band credit-memo freeze (release #5 / refund F5), and the refund reconciler's
 * post-FAILURE freeze (F6). A frozen segment is never auto-processed again and — via parent-last
 * (INV-14) — can block the order's parent commission indefinitely, yet only a ONE-SHOT CRITICAL is
 * raised at placement (there is no hold-clear path today). This reader feeds the daily strand-alarm
 * scan so a frozen segment is never a SILENT strand (the RBI "never strand unalarmed" rule).
 *
 * Keys off the hold row's created_at (when the freeze happened). Covers BOTH vendor and parent
 * segments (the parent can be frozen too — #5/F3). Excludes terminal segments (a frozen-then-
 * released/refunded segment is moot). The unique (segment_id, hold_type) index means at most one
 * FROZEN row per segment, so no grouping is needed. Guard-compliant: getConnection()->select(), no
 * raw SQL literals, no ResourceConnection.
 */
class StaleFrozenSegments extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_segment_hold', 'hold_id');
    }

    /**
     * @param string $cutoffGmt Holds created strictly before this 'Y-m-d H:i:s' are stale
     * @return array<int, array<string, string|null>> Rows: segment_id, order_id, seller_id, hold_type, frozen_at, note
     */
    public function getStaleFrozenSegments(string $cutoffGmt): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(
                ['h' => $this->getMainTable()],
                [
                    'segment_id' => 'h.segment_id',
                    'hold_type' => 'h.hold_type',
                    'frozen_at' => 'h.created_at',
                    'note' => 'h.note',
                ]
            )
            ->join(
                ['seg' => $this->getTable('rkd_payu_split_segment')],
                'seg.segment_id = h.segment_id',
                ['order_id' => 'seg.order_id', 'seller_id' => 'seg.seller_id']
            )
            ->join(
                ['sp' => $this->getTable('rkd_payu_split')],
                'sp.entity_id = seg.split_id',
                []
            )
            ->where('h.hold_type = ?', SplitHold::TYPE_FROZEN)
            ->where('h.created_at < ?', $cutoffGmt)
            ->where('sp.split_state = ?', SplitOrder::STATE_DONE)
            ->where('seg.state NOT IN (?)', SplitSegment::TERMINAL_STATES);

        return $connection->fetchAll($select);
    }
}
