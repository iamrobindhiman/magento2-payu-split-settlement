<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitRefund;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Selects the refund legs the refund reconciler NEVER resolves, older than a cutoff (gate item #13).
 * Two kinds:
 *   - a HELD freeze marker (R-6 retry-cap, R-7 anomaly, negative/unfundable, F7 null-request-id) —
 *     parked for manual reconciliation, nothing re-examines it;
 *   - a PENDING/QUEUED leg with NO PayU request id — the FULL_UNWIND shipping obligation and the
 *     absent-parent commission leg. They are not pollable, so RefundLegLoader excludes them and
 *     RefundReconciler early-returns BEFORE its own age-alarm.
 * Every OTHER in-flight leg (QUEUED/PENDING WITH a request id) is polled and strand-alarmed by the
 * reconciler, so it is deliberately NOT selected here. These two kinds each block the segment's
 * release (releaseBlockingLegs / INV-19) and, via parent-last, the parent commission — with no recurring
 * alarm and, for the absent-parent leg, no recovery surface. This reader feeds the daily strand
 * alarm so they are never a SILENT strand (the RBI "never strand unalarmed" rule).
 *
 * Keys off the leg's created_at. joinLeft to the segment (for seller_id) so a strand can never be
 * hidden by a join miss. Guard-compliant: getConnection()->select(), no raw SQL literals (the
 * OR-clause values are bound via quoteInto), no ResourceConnection.
 */
class StaleRefundLegs extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_refund', 'refund_id');
    }

    /**
     * @param string $cutoffGmt Legs created strictly before this 'Y-m-d H:i:s' are stale
     * @return array<int, array<string, string|null>> Rows: refund_id, order_id, segment_id, seller_id, state, reason, created_at
     */
    public function getStaleUnhandledLegs(string $cutoffGmt): array
    {
        $connection = $this->getConnection();

        // The two reconciler-blind kinds (values bound via quoteInto — no raw literals). where()
        // wraps this whole condition in parentheses, so the OR groups correctly against the cutoff.
        $unhandled = $connection->quoteInto('r.state = ?', SplitRefund::STATE_HELD)
            . ' OR ('
            . $connection->quoteInto('r.state IN (?)', [SplitRefund::STATE_PENDING, SplitRefund::STATE_QUEUED])
            . ' AND r.payu_request_id IS NULL)';

        $select = $connection->select()
            ->from(
                ['r' => $this->getMainTable()],
                [
                    'refund_id' => 'r.refund_id',
                    'order_id' => 'r.order_id',
                    'segment_id' => 'r.segment_id',
                    'state' => 'r.state',
                    'reason' => 'r.reason',
                    'created_at' => 'r.created_at',
                ]
            )
            ->joinLeft(
                ['seg' => $this->getTable('rkd_payu_split_segment')],
                'seg.segment_id = r.segment_id',
                ['seller_id' => 'seg.seller_id']
            )
            ->where($unhandled)
            ->where('r.created_at < ?', $cutoffGmt);

        return $connection->fetchAll($select);
    }
}
