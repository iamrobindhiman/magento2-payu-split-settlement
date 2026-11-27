<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Sql\Expression;
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

    /**
     * Total release_settlement POSTs allowed per segment (the initial fire by ReleaseProcessor =
     * attempt 1, plus recon re-fires up to this cap). #10: a transport-failed initial release never
     * reaches PayU, so it yields no settlement signal forever; recon re-fires it (idempotent at
     * PayU — an already-accepted release dedups, so a re-fire of a landed-but-pending one is a
     * benign no-op). Bounded so a never-settling segment does not POST every recon tick across the
     * whole T+1 window before the strand alarm finally fires.
     */
    private const MAX_RELEASE_ATTEMPTS = 4;

    /**
     * Days a segment must sit in RELEASE_REQUESTED with NO settlement signal before recon re-fires
     * (#10, money-review F1). A normal landed release settles + signals within T+1, so it is
     * RELEASED before this grace and never re-fired — re-firing only kicks in for an OVERDUE release
     * (the transport-failed case), so we never re-POST a still-pending release and lean on PayU's
     * dedup. An O-11 calibration knob (settlement/readback latency); release is gated OFF until then.
     */
    private const REFIRE_GRACE_DAYS = 2;

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
     * @param CreditMemoRefundReader $creditMemoReader
     * @param SegmentItemLoader $itemLoader
     * @param ReleaseRaceGuard $raceGuard
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
        private readonly CreditMemoRefundReader $creditMemoReader,
        private readonly SegmentItemLoader $itemLoader,
        private readonly ReleaseRaceGuard $raceGuard,
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

        // Gate item #5 (recon side): an out-of-band Magento credit memo that landed AFTER this release
        // was fired (in the settlement window). The INV-18 equality below — settled == amount_paise −
        // refunded_paise — is blind to base_amount_refunded (a Magento credit memo bumps NEITHER side:
        // refunded_paise is only bumped by the var8 split-refund path), so an offline credit memo
        // would let the segment auto-promote to RELEASED and mirror an over-pay to Webkul with NO
        // alarm. Mirror the ReleaseProcessor #5 gate here: FREEZE + CRITICAL, never RELEASED. The
        // money already left when the release fired, so recovery is a human clawback (A8) — this just
        // makes it loud + queryable instead of silent. Vendor-only: the parent commission has no
        // items, so the reader reads 0. Checked BEFORE the gateway readback so a credit-memo'd segment
        // costs no PayU call.
        // Per-item base_amount_refunded PLUS the order-level out-of-band refund (adjustment/shipping
        // credit memo, F3) the per-item read misses — the latter also covers the parent once O-13
        // wires its child_merchant_key so it reaches this check.
        $creditMemoPaise = $this->creditMemoReader->refundedPaiseForItems(
            $this->itemLoader->loadOrderItemIds($segmentId)
        ) + $this->creditMemoReader->orderLevelRefundPaise((int) $segment->getOrderId());
        if ($creditMemoPaise > 0) {
            $this->holdWriter->place(
                $segmentId,
                SplitHold::TYPE_FROZEN,
                sprintf(
                    'Out-of-band Magento credit memo (%d paise) after the release was requested — frozen '
                    . 'for clawback, never released (gate item #5).',
                    $creditMemoPaise
                )
            );
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: segment %d (order %d) FROZEN at settlement readback — %d paise refunded '
                . 'out-of-band via a Magento credit memo after the release fired. Manual reconciliation '
                . '(clawback) required.',
                $segmentId,
                $segment->getOrderId(),
                $creditMemoPaise
            ));
            return;
        }

        $expectedNet = $segment->getAmountPaise() - $segment->getRefundedPaise();

        [$from, $to] = $this->window($segment, $header);
        $response = $this->gateway->getAggregatorTransactions($from, $to, 1, $this->config->getScanBatchSize());
        $readback = $this->readbackReader->read($response, $mihpayid, $childKey);

        if (!$readback->hasSettlementSignal) {
            $this->handleNoSignal($segment, $mihpayid);
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
     * No settlement evidence yet. Three outcomes, in order:
     *   1. stranded past the alert threshold → CRITICAL alarm (a settlement that never reconciled —
     *      the silent-strand RBI risk this scan exists to prevent). Checked FIRST so a genuinely
     *      stranded segment always alarms regardless of attempt budget.
     *   2. within the attempt budget → RE-FIRE releaseSettlement (#10): heals a transport-failed
     *      initial release that never reached PayU. Idempotent at PayU, so a re-fire of a
     *      landed-but-pending release is a benign no-op. The bump bounds the re-fires.
     *   3. budget exhausted (or no child MID) → leave RELEASE_REQUESTED, wait for the next tick.
     *
     * @param SplitSegment $segment
     * @param string $mihpayid
     * @return void
     */
    private function handleNoSignal(SplitSegment $segment, string $mihpayid): void
    {
        $segmentId = (int) $segment->getId();
        $strandedDays = $this->strandedDays($segment);

        if ($strandedDays >= $this->config->getAlertDays()) {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: segment %d (order %d) stranded in RELEASE_REQUESTED past the alert '
                . 'threshold with no settlement readback — investigate.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        // Within the settlement window: a normal landed release settles + signals here, so do NOT
        // re-fire yet (re-POSTing a still-pending release would lean on PayU's dedup). Just wait.
        if ($strandedDays < self::REFIRE_GRACE_DAYS) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d (order %d) awaiting settlement readback within the settlement window.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        // Overdue with no signal (the transport-failed case): re-fire, bounded by attempts.
        $childMid = $segment->getChildMid();
        if ($segment->getReleaseAttempts() >= self::MAX_RELEASE_ATTEMPTS || $childMid === null) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d (order %d) overdue with re-fire budget spent — still '
                . 'RELEASE_REQUESTED, awaiting the strand alarm.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        // F2: the overdue re-fire is a REAL disbursement — the initial POST transport-failed, so the
        // money has NOT moved. A cancel/return/hold/refund that landed during the strand window must
        // block it, exactly as ReleaseProcessor's post-claim re-check (the shared ReleaseRaceGuard)
        // blocks the initial fire. Without this the recon re-checks nothing but the FROZEN hold and
        // would steamroll a segment that became ineligible mid-strand into a disbursement to a
        // no-longer-eligible vendor. Freeze + CRITICAL (a human decides whether the original POST
        // landed — yes ⇒ A8 clawback; no ⇒ the freeze correctly stops the disbursement) rather than
        // re-firing. The FROZEN hold also drops it from any future reconcile (checked up top).
        if ($this->raceGuard->racedIntoIneligibility($segment)) {
            $this->holdWriter->place(
                $segmentId,
                SplitHold::TYPE_FROZEN,
                'A cancel/return/hold/refund landed while the release was stranded — overdue re-fire '
                . 'blocked, frozen for reconciliation (gate item F2).'
            );
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: segment %d (order %d) FROZEN — became ineligible (cancel/return/hold/refund) '
                . 'during the release strand; overdue re-fire blocked. Manual reconciliation required.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        // Claim-before-fire (mirror ReleaseProcessor): atomically consume a budget slot conditional
        // on the row still being RELEASE_REQUESTED, so a segment a concurrent tick promoted (RELEASED)
        // or froze never gets a re-POST. Only fire if we won the claim.
        if (!$this->claimReFire($segment)) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d no longer RELEASE_REQUESTED at the re-fire claim — not re-firing.',
                $segmentId
            ));
            return;
        }

        try {
            $this->gateway->releaseSettlement($mihpayid, $childMid);
            $this->logger->info(sprintf(
                'RKD_PayuSplit: re-fired overdue release for segment %d (order %d) — attempt %d/%d. '
                . 'PayU dedups an already-accepted release.',
                $segmentId,
                $segment->getOrderId(),
                $segment->getReleaseAttempts(),
                self::MAX_RELEASE_ATTEMPTS
            ));
        } catch (PayuSplitRequestException $e) {
            // Transport failure on the re-fire — the slot is spent; retry next tick if budget remains.
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: transport error re-firing release for segment %d — retry next tick: %s',
                $segmentId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Atomically consume one re-fire budget slot: increment release_attempts conditional on the row
     * still being RELEASE_REQUESTED. Returns true (and bumps the in-memory model) only when this
     * call won the claim (1 row affected); false means a concurrent tick already promoted/froze the
     * row, so the caller must NOT fire a re-POST against it.
     *
     * @param SplitSegment $segment
     * @return bool
     */
    private function claimReFire(SplitSegment $segment): bool
    {
        $connection = $this->segmentResource->getConnection();
        $claimed = (int) $connection->update(
            $this->segmentResource->getMainTable(),
            ['release_attempts' => new Expression('release_attempts + 1')],
            [
                'segment_id = ?' => (int) $segment->getId(),
                'state = ?' => SplitSegment::STATE_RELEASE_REQUESTED,
            ]
        );
        if ($claimed !== 1) {
            return false;
        }
        $segment->setData('release_attempts', $segment->getReleaseAttempts() + 1);
        return true;
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
