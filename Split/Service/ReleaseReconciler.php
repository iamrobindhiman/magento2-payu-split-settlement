<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * The release readback reconciliation (A5c-5) — the second half of the keystone. A5c-2's
 * ReleaseProcessor fires release_settlement and stops at RELEASE_REQUESTED because PayU
 * settlement is async (T+1) and its sync response is ambiguous (E-07). This reconciler is the
 * ONLY path that promotes RELEASE_REQUESTED → RELEASED, and it does so only on positive
 * settlement evidence read back from PayU (INV-18):
 *
 *   readback get_aggregator_transactions →
 *     • no settlement signal  → leave RELEASE_REQUESTED, retry next tick (the only UAT path,
 *                               E-07); alarm if stranded past the alert threshold.
 *     • settled == amount−refunded → RELEASED (+ released_paise/released_at) + dispatch
 *                               rkd_payu_segment_released for the Webkul ledger mirror (A5c-4).
 *     • settled != amount−refunded → FROZEN hold + CRITICAL alarm; NEVER RELEASED (hard stop,
 *                               not a retry — INV-18). Reconciled by a human.
 *
 * Vendor and parent (commission) segments are handled uniformly here; parent-last (INV-14) is
 * enforced upstream in ReleaseProcessor, so a parent segment only ever reaches RELEASE_REQUESTED
 * after every vendor segment is terminal. The promotion is a compare-and-set (RELEASE_REQUESTED
 * → RELEASED only if still RELEASE_REQUESTED) so two overlapping recon ticks can't double-promote
 * or double-dispatch (INV-6 / S-34). Release is PROD-VERIFY only (E-07 → O-11): in UAT the reader
 * never signals, so this whole path is a safe no-op until the O-11 calibration.
 */
class ReleaseReconciler
{
    /**
     * Dispatched once when a segment is confirmed RELEASED — A5c-4 mirrors it into Webkul's
     * ledger and suppresses the native "Pay Seller" (INV-17). The dispatch fires AFTER the CAS
     * commit (so a re-run never double-promotes), which means a crash between the commit and the
     * listener leaves the segment RELEASED but un-mirrored — and the loader (state=RELEASE_REQUESTED)
     * will never re-select it to re-fire the event. CONTRACT for A5c-4: the listener MUST be
     * idempotent on segment_id, and the mirror MUST be backed by a pull/reconcile sweep (find
     * RELEASED segments with no Webkul-mirror stamp and mirror them) so a lost dispatch self-heals.
     * Do NOT "fix" the gap by dispatching before the CAS — that would trade a safe strand for an
     * unsafe double-pay.
     */
    public const EVENT_SEGMENT_RELEASED = 'rkd_payu_segment_released';

    /** Default readback look-back (days) when a segment has no release_requested_at anchor. */
    private const DEFAULT_LOOKBACK_DAYS = 7;

    /** Buffer subtracted from the anchor so a boundary-time settlement is inside the window. */
    private const WINDOW_BUFFER_DAYS = 1;

    /**
     * @param PayuSplitClient $gateway
     * @param SplitOrderRepositoryInterface $repository
     * @param SettlementReadbackReader $readbackReader
     * @param SegmentHoldLoader $holdLoader
     * @param SegmentHoldWriter $holdWriter
     * @param SplitSegmentResource $segmentResource
     * @param SplitConfig $config
     * @param DateTime $dateTime
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuSplitClient $gateway,
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly SettlementReadbackReader $readbackReader,
        private readonly SegmentHoldLoader $holdLoader,
        private readonly SegmentHoldWriter $holdWriter,
        private readonly SplitSegmentResource $segmentResource,
        private readonly SplitConfig $config,
        private readonly DateTime $dateTime,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param SplitSegment $segment
     * @return void
     */
    public function reconcile(SplitSegment $segment): void
    {
        $segmentId = (int) $segment->getId();

        // Scope + idempotency: only a RELEASE_REQUESTED segment is reconciled. A RELEASED one (a
        // concurrent recon) or any other state is left untouched — no second readback, no second
        // dispatch.
        if ($segment->getState() !== SplitSegment::STATE_RELEASE_REQUESTED) {
            return;
        }

        // A prior readback mismatch froze this segment — never auto-process it again. Skip BEFORE
        // any gateway call so a frozen segment costs nothing per tick.
        if (in_array(SplitHold::TYPE_FROZEN, $this->holdLoader->holdTypes($segmentId), true)) {
            return;
        }

        $header = $this->repository->getByOrderId($segment->getOrderId());
        $mihpayid = $header !== null ? (string) $header->getMihpayid() : '';
        if ($mihpayid === '') {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: cannot reconcile release of segment %d — order %d has no mihpayid.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        $childKey = $segment->getChildMerchantKey();
        if ($childKey === null) {
            // A RELEASE_REQUESTED segment with no child merchant key cannot be matched in the
            // aggregator readback. In practice this is the PARENT commission segment: it is
            // persisted with child_merchant_key = null (SplitOrderManager), so once the O-13
            // release wiring sets its child_mid (letting ReleaseProcessor fire) but NOT its
            // child_merchant_key, the recon has no key to match. That is a structural wiring gap,
            // not a transient — FREEZE it (durable surface via the hold note + stops per-tick
            // CRITICAL noise) and alarm once. A human completes the O-13 parent-commission key
            // wiring and clears the hold. Vendor segments always carry a key (an unmapped vendor
            // makes A4 SKIP the whole header), so this only ever fires for the parent.
            $this->holdWriter->place(
                $segmentId,
                SplitHold::TYPE_FROZEN,
                'No child_merchant_key for the settlement readback (O-13 parent-commission wiring incomplete).'
            );
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: segment %d (order %d) FROZEN — no child merchant key for the settlement '
                . 'readback (parent O-13 wiring incomplete). Manual reconciliation required.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        $expectedNet = $segment->getAmountPaise() - $segment->getRefundedPaise();

        [$from, $to] = $this->window($segment, $header);
        $response = $this->gateway->getAggregatorTransactions($from, $to, 1, $this->config->getScanBatchSize());
        $readback = $this->readbackReader->read($response, $mihpayid, $childKey);

        if (!$readback->hasSettlementSignal) {
            $this->handleNoSignal($segment);
            return;
        }

        if ($readback->settledPaise === $expectedNet) {
            if ($this->markReleased($segment, $expectedNet)) {
                $this->eventManager->dispatch(self::EVENT_SEGMENT_RELEASED, ['segment' => $segment]);
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: segment %d (order %d, child %s) RELEASED — settled %d paise.',
                    $segmentId,
                    $segment->getOrderId(),
                    $childKey,
                    $expectedNet
                ));
            }
            return;
        }

        // INV-18 hard stop: PayU settled a different amount than our books expect — freeze and
        // alarm, never RELEASED. A partial refund that did not reach refunded_paise, a wrong split,
        // or a desync would land here. A human reconciles it.
        $this->holdWriter->place(
            $segmentId,
            SplitHold::TYPE_FROZEN,
            sprintf(
                'INV-18 readback mismatch: expected %d paise (amount %d - refunded %d); PayU settled %d.',
                $expectedNet,
                $segment->getAmountPaise(),
                $segment->getRefundedPaise(),
                $readback->settledPaise
            )
        );
        $this->logger->critical(sprintf(
            'RKD_PayuSplit: segment %d (order %d, child %s) FROZEN — INV-18 readback mismatch: '
            . 'expected %d paise, PayU settled %d. Manual reconciliation required.',
            $segmentId,
            $segment->getOrderId(),
            $childKey,
            $expectedNet,
            $readback->settledPaise
        ));
    }

    /**
     * No settlement evidence yet — the segment stays RELEASE_REQUESTED for the next tick. Alarm
     * if it has been stranded past the alert threshold (a settlement that never reconciled — a
     * silent strand is exactly the RBI-compliance risk this scan exists to prevent).
     *
     * @param SplitSegment $segment
     * @return void
     */
    private function handleNoSignal(SplitSegment $segment): void
    {
        $segmentId = (int) $segment->getId();
        if ($this->strandedDays($segment) >= $this->config->getAlertDays()) {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: segment %d (order %d) stranded in RELEASE_REQUESTED past the alert '
                . 'threshold with no settlement readback — investigate.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }
        $this->logger->info(sprintf(
            'RKD_PayuSplit: segment %d (order %d) awaiting settlement readback — still RELEASE_REQUESTED.',
            $segmentId,
            $segment->getOrderId()
        ));
    }

    /**
     * Compare-and-set the segment to RELEASED. The conditional UPDATE flips only if the row is
     * still RELEASE_REQUESTED, so a concurrent recon tick that already promoted it affects 0 rows
     * and we neither re-stamp nor re-dispatch.
     *
     * @param SplitSegment $segment
     * @param int $settledPaise
     * @return bool True when this call performed the promotion
     */
    private function markReleased(SplitSegment $segment, int $settledPaise): bool
    {
        $now = $this->dateTime->gmtDate();
        $connection = $this->segmentResource->getConnection();
        $updated = (int) $connection->update(
            $this->segmentResource->getMainTable(),
            [
                'state' => SplitSegment::STATE_RELEASED,
                'released_paise' => $settledPaise,
                'released_at' => $now,
                'last_error' => null,
            ],
            [
                'segment_id = ?' => (int) $segment->getId(),
                'state = ?' => SplitSegment::STATE_RELEASE_REQUESTED,
            ]
        );
        if ($updated === 0) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d already promoted out of RELEASE_REQUESTED by a concurrent recon.',
                (int) $segment->getId()
            ));
            return false;
        }
        // Reflect the promotion on the in-memory model the dispatched event carries.
        $segment->setState(SplitSegment::STATE_RELEASED);
        $segment->setData('released_paise', $settledPaise);
        $segment->setData('released_at', $now);
        return true;
    }

    /**
     * The from/to window for the aggregator readback: from just before the release was requested
     * to now, so the settlement (which lands at/after the request) is inside it.
     *
     * @param SplitSegment $segment
     * @param SplitOrder|null $header
     * @return array{0: string, 1: string} from, to as 'Y-m-d H:i'
     */
    private function window(SplitSegment $segment, ?SplitOrder $header): array
    {
        $now = $this->dateTime->gmtDate();
        $anchor = $segment->getReleaseRequestedAt();
        if ($anchor === null && $header !== null) {
            $anchor = $header->getData('split_done_at') ?: $header->getData('paid_at');
        }

        try {
            $to = new \DateTimeImmutable($now);
            $base = $anchor !== null && $anchor !== ''
                ? new \DateTimeImmutable((string) $anchor)
                : $to->sub(new \DateInterval('P' . self::DEFAULT_LOOKBACK_DAYS . 'D'));
            $from = $base->sub(new \DateInterval('P' . self::WINDOW_BUFFER_DAYS . 'D'));
            return [$from->format('Y-m-d H:i'), $to->format('Y-m-d H:i')];
        } catch (\Throwable $t) {
            // A malformed timestamp must never crash the scan (F-8). Fall back to a now-anchored
            // zero-width window; worst case the readback finds nothing and we retry next tick.
            // Trim gmtDate()'s 'Y-m-d H:i:s' to the 'Y-m-d H:i' the get_aggregator_transactions
            // contract wants (no seconds) — keep it consistent with the happy path's format().
            $nowMinute = substr($now, 0, 16);
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: bad timestamp building readback window for segment %d — using default lookback (%s).',
                (int) $segment->getId(),
                $t->getMessage()
            ));
            return [$nowMinute, $nowMinute];
        }
    }

    /**
     * Whole days the segment has sat in RELEASE_REQUESTED, by its release_requested_at anchor.
     *
     * Fail-loud on a missing/unparseable anchor: the ReleaseProcessor CAS claim always stamps the
     * anchor in the same atomic UPDATE as the state flip, so a RELEASE_REQUESTED segment WITHOUT
     * one is a corruption/legacy/hand-edited row — exactly the silent-strand the RBI "never strand
     * unalarmed" rule forbids. We therefore return the maximum (always ≥ any alert threshold) so
     * the no-signal path alarms, rather than returning 0 (which would hide a missing anchor behind
     * "not yet stranded"). A missing anchor must be a LOUDER signal than an old one, never quieter.
     *
     * @param SplitSegment $segment
     * @return int
     */
    private function strandedDays(SplitSegment $segment): int
    {
        $anchor = $segment->getReleaseRequestedAt();
        if ($anchor === null) {
            return PHP_INT_MAX;
        }
        try {
            $requested = new \DateTimeImmutable($anchor);
            $now = new \DateTimeImmutable($this->dateTime->gmtDate());
            return (int) $requested->diff($now)->days;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
