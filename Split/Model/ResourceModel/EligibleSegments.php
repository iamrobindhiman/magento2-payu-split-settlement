<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Coarse pre-filter for the release scan: vendor segments on a DONE split, in a releasable
 * state, with EVERY item delivered, no active hold, and no in-flight refund leg. This is a
 * cheap stable narrowing only — the precise per-item window check + the eligibility re-check
 * (under a row lock) happen in ReleaseProcessor, which stays the single source of truth for
 * the time-dependent gate. Mirrors RefundableSegments' anti-join idiom.
 *
 * Guard-compliant: getConnection()->select(), no raw SQL literals, no ResourceConnection.
 */
class EligibleSegments extends AbstractDb
{
    /**
     * Segment states from which a release may still proceed (not terminal, not in-flight).
     */
    private const RELEASABLE_STATES = [
        SplitSegment::STATE_PENDING,
        SplitSegment::STATE_AWAITING_DELIVERY,
        SplitSegment::STATE_IN_WINDOW,
        SplitSegment::STATE_ELIGIBLE,
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
    public function getReleasableVendorRows(): array
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
            // Anti-join: any item still undelivered leaves a matched row → excluded below.
            ->joinLeft(
                ['it_un' => $this->getTable('rkd_payu_segment_item')],
                'it_un.segment_id = seg.segment_id AND it_un.delivered_at IS NULL',
                []
            )
            ->joinLeft(
                ['h' => $this->getTable('rkd_payu_segment_hold')],
                'h.segment_id = seg.segment_id',
                []
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
            ->where('seg.segment_type = ?', SplitSegment::TYPE_VENDOR)
            ->where('seg.state IN (?)', self::RELEASABLE_STATES)
            // Mapped child MID only — an unmapped segment can't be released; excluding it here
            // avoids the processor re-selecting it every tick and CRITICAL-logging the gap.
            ->where('seg.child_mid IS NOT NULL')
            ->where('seg.child_mid != ?', '')
            ->where('it_un.item_row_id IS NULL')   // no undelivered item → delivery complete
            ->where('h.hold_id IS NULL')           // no active hold (INV — holds block release)
            ->where('r.refund_id IS NULL')         // no in-flight refund leg (INV-19)
            ->group('seg.segment_id');

        return $connection->fetchAll($select);
    }

    /**
     * Coarse pre-filter for the PARENT (commission) segment, deliberately parallel to
     * getReleasableVendorRows() minus the per-item delivery anti-join — the parent has no items.
     * Parent-last (INV-14: release only once EVERY vendor segment of the order is terminal) is NOT
     * checked here; it stays the single responsibility of ReleaseProcessor::isParentReleasable,
     * which re-checks under the row lock. This is only the cheap stable narrowing: a commission
     * segment on a DONE split, in a releasable state, with a mapped child MID, no hold, no in-flight
     * refund leg. The child_mid filter means the parent stays excluded until the O-13 wiring
     * populates it — so a pre-O-13 parent is never fed to the processor (no premature release, no
     * per-tick CRITICAL).
     *
     * @return array<int, array<string, string>> Rows: segment_id, order_id, seller_id
     */
    public function getReleasableParentRows(): array
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
            ->joinLeft(
                ['h' => $this->getTable('rkd_payu_segment_hold')],
                'h.segment_id = seg.segment_id',
                []
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
            ->where('seg.segment_type = ?', SplitSegment::TYPE_COMMISSION)
            ->where('seg.state IN (?)', self::RELEASABLE_STATES)
            // Mapped child MID only — the parent stays excluded until O-13 wiring sets it.
            ->where('seg.child_mid IS NOT NULL')
            ->where('seg.child_mid != ?', '')
            ->where('h.hold_id IS NULL')           // no active hold (INV — holds block release)
            ->where('r.refund_id IS NULL')         // no in-flight refund leg (INV-19)
            ->group('seg.segment_id');

        return $connection->fetchAll($select);
    }
}
