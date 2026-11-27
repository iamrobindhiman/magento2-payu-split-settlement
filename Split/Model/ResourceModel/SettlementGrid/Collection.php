<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SettlementGrid;

use RKD\PayuSplit\Split\Model\ItemGate;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment as SplitSegmentModel;
use RKD\PayuSplit\Split\Service\SettlementRowPresenter;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Read-only grid collection over rkd_payu_split_segment for the A7 admin settlement view.
 * Each loaded segment is decorated (in _afterLoad, batched — no N+1) with display-only
 * computed columns: paise amounts rendered as exact rupee strings, the release-eligibility
 * reason from the canonical SegmentEligibility (so admin and cron agree), and a compact
 * refund-leg summary. The raw segment columns remain the authoritative, sortable/filterable
 * source; the computed columns are presentation only.
 *
 * Guard-clean: related rows are read through the collection's own getConnection()->select()
 * with quoteInto/array IN binding — no raw SQL literals; timestamps via the injected
 * DateTime, never date()/time(); money formatted from integer paise, never float math.
 */
class Collection extends AbstractCollection
{
    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param SettlementRowPresenter $presenter
     * @param DateTime $dateTime
     * @param AdapterInterface|null $connection
     * @param AbstractDb|null $resource
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly SettlementRowPresenter $presenter,
        private readonly DateTime $dateTime,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitSegmentModel::class, SplitSegmentResource::class);
    }

    /**
     * Decorate each loaded segment with the display-only computed columns.
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        $segmentIds = [];
        $splitIds = [];
        foreach ($this->getItems() as $segment) {
            $segmentIds[] = (int) $segment->getId();
            $splitIds[] = (int) $segment->getData('split_id');
        }
        if ($segmentIds === []) {
            return $this;
        }

        $itemGates = $this->loadItemGates($segmentIds);
        $holds = $this->loadHolds($segmentIds);
        $refundStates = $this->loadRefundStates($segmentIds);
        $splitStates = $this->loadSplitStates(array_values(array_unique($splitIds)));
        $now = $this->dateTime->gmtDate();

        foreach ($this->getItems() as $segment) {
            $segmentId = (int) $segment->getId();
            $segmentType = (string) $segment->getData('segment_type');
            $legStates = $refundStates[$segmentId] ?? [];
            // A HELD leg is a frozen, still-owed refund — it must block release just as
            // PENDING/QUEUED do (INV-19), else a segment with stuck customer money could be
            // released. (A5c's release gate MUST apply the same HELD rule — see SegmentEligibility.)
            $hasPendingRefund = array_intersect(
                $legStates,
                [SplitRefund::STATE_PENDING, SplitRefund::STATE_QUEUED, SplitRefund::STATE_HELD]
            ) !== [];

            $segment->setData('vendor_label', $this->presenter->vendorLabel(
                $segmentType,
                (int) $segment->getData('seller_id')
            ));
            $segment->setData('amount_display', $this->presenter->rupees((int) $segment->getData('amount_paise')));
            $segment->setData('refunded_display', $this->presenter->rupees((int) $segment->getData('refunded_paise')));
            $segment->setData('released_display', $this->presenter->rupees((int) $segment->getData('released_paise')));
            $segment->setData('eligibility_reason', $this->presenter->releaseGate(
                $segmentType,
                (string) $segment->getData('state'),
                $itemGates[$segmentId] ?? [],
                $holds[$segmentId] ?? [],
                $hasPendingRefund,
                $now
            ));
            $segment->setData('refund_summary', $this->presenter->refundSummary($legStates));

            // A7-3 row-action gating (display-only flags): the header state drives "Retry
            // Split"; a HELD leg drives "Re-arm Refund".
            $segment->setData('split_state', $splitStates[(int) $segment->getData('split_id')] ?? '');
            $segment->setData(
                'has_held_refund',
                in_array(SplitRefund::STATE_HELD, $legStates, true) ? 1 : 0
            );
        }

        return $this;
    }

    /**
     * @param int[] $segmentIds
     * @return array<int, ItemGate[]> Keyed by segment_id
     */
    private function loadItemGates(array $segmentIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                $this->getTable('rkd_payu_segment_item'),
                ['segment_id', 'delivered_at', 'window_closes_at', 'return_state']
            )
            ->where('segment_id IN (?)', $segmentIds);

        $gates = [];
        foreach ($connection->fetchAll($select) as $row) {
            $gates[(int) $row['segment_id']][] = new ItemGate(
                $row['delivered_at'] !== null ? (string) $row['delivered_at'] : null,
                $row['window_closes_at'] !== null ? (string) $row['window_closes_at'] : null,
                (string) $row['return_state']
            );
        }
        return $gates;
    }

    /**
     * @param int[] $segmentIds
     * @return array<int, string[]> Active hold types keyed by segment_id
     */
    private function loadHolds(array $segmentIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('rkd_payu_segment_hold'), ['segment_id', 'hold_type'])
            ->where('segment_id IN (?)', $segmentIds);

        $holds = [];
        foreach ($connection->fetchAll($select) as $row) {
            $holds[(int) $row['segment_id']][] = (string) $row['hold_type'];
        }
        return $holds;
    }

    /**
     * @param int[] $segmentIds
     * @return array<int, string[]> Refund-leg states keyed by segment_id
     */
    private function loadRefundStates(array $segmentIds): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('rkd_payu_refund'), ['segment_id', 'state'])
            ->where('segment_id IN (?)', $segmentIds);

        $states = [];
        foreach ($connection->fetchAll($select) as $row) {
            $states[(int) $row['segment_id']][] = (string) $row['state'];
        }
        return $states;
    }

    /**
     * @param int[] $splitIds
     * @return array<int, string> Header split_state keyed by split header entity_id
     */
    private function loadSplitStates(array $splitIds): array
    {
        if ($splitIds === []) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable('rkd_payu_split'), ['entity_id', 'split_state'])
            ->where('entity_id IN (?)', $splitIds);

        $states = [];
        foreach ($connection->fetchAll($select) as $row) {
            $states[(int) $row['entity_id']] = (string) $row['split_state'];
        }
        return $states;
    }
}
