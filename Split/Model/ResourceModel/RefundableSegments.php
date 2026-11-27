<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Selects the vendor segments that need a customer refund: their order's split is DONE
 * (Fact 4), their Webkul portion is cancelled (marketplace_orders.is_canceled = 1), the
 * segment is not already terminal, and no non-FAILED refund leg exists yet (so a re-run
 * never double-fires). Returns the cancel actor too, so the scan can attribute the
 * refund reason. A REFUND_PENDING segment with no active leg (a write-ahead crash) is
 * deliberately INCLUDED so the executor can retry it with its stored token.
 *
 * Read through our own resource model (guard-compliant getConnection()) — no raw SQL
 * literals, no ResourceConnection.
 */
class RefundableSegments extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_split_segment', 'segment_id');
    }

    /**
     * @return array<int, array<string, string|null>> Rows: segment_id, cancelled_by_type
     */
    public function getRefundableRows(): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(['seg' => $this->getMainTable()], ['segment_id' => 'seg.segment_id'])
            ->join(
                ['sp' => $this->getTable('rkd_payu_split')],
                'seg.split_id = sp.entity_id',
                []
            )
            ->join(
                ['mo' => $this->getTable('marketplace_orders')],
                'mo.order_id = seg.order_id AND mo.seller_id = seg.seller_id',
                ['cancelled_by_type' => new Expression('MAX(mo.cancelled_by_type)')]
            )
            ->joinLeft(
                ['r' => $this->getTable('rkd_payu_refund')],
                $connection->quoteInto(
                    'r.segment_id = seg.segment_id AND r.state NOT IN (?)',
                    [SplitRefund::STATE_FAILED, SplitRefund::STATE_REARMED]
                ),
                []
            )
            ->where('sp.split_state = ?', SplitOrder::STATE_DONE)
            ->where('mo.is_canceled = ?', 1)
            ->where('seg.segment_type = ?', SplitSegment::TYPE_VENDOR)
            // REFUNDED / CANCELLED are excluded; PARTIAL_REFUNDED is deliberately NOT —
            // a partially-refunded segment that still owes a further refund (v2 returns)
            // must remain selectable. It is harmless for the cancellation scope because the
            // "no active leg" filter below excludes any segment that already has a
            // PENDING/QUEUED/SUCCESS leg, so the executor never re-fires one in flight.
            ->where('seg.state NOT IN (?)', [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_CANCELLED])
            // No blocking refund leg exists (the join matched only non-FAILED, non-REARMED
            // legs, so a NULL means none) — re-selects FAILED-only, admin-re-armed, and
            // write-ahead-crash segments.
            ->where('r.refund_id IS NULL')
            ->group('seg.segment_id');

        return $connection->fetchAll($select);
    }
}
