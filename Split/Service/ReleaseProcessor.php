<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * The release keystone (A5c-2). For one eligible held segment it writes a RELEASE_REQUESTED
 * marker AHEAD of firing release_settlement (crash-safety), then fires exactly one release
 * request to PayU. It deliberately stops at RELEASE_REQUESTED: PayU settlement is async (T+1)
 * and the sync response is ambiguous (status:0 means both success and a generic error per
 * E-07), so RELEASED is only ever reached by the later readback recon (A5c-5) that confirms
 * the settled amount (INV-18). Idempotency rests on PayU's own "already accepted" — a re-fire
 * for an (mihpayid, childMid) already released is a benign no-op.
 *
 * Gates (refuse to even request a release):
 *  - vendor: SegmentEligibility — every item delivered + past its window, no open return, no
 *    active hold, and NO release-blocking refund leg on the segment (INV-19: a PENDING/QUEUED/HELD
 *    refund blocks release; a COMPLETED SUCCESS refund does NOT — #12 releases the remainder);
 *  - parent commission (seller_id 0): releases LAST (INV-14) — only once EVERY vendor segment
 *    of the order is terminal, and the parent itself carries no hold and no in-flight refund.
 */
class ReleaseProcessor
{
    /**
     * @param PayuSplitClient $gateway
     * @param SplitOrderRepositoryInterface $repository
     * @param SegmentEligibility $eligibility
     * @param SegmentItemLoader $itemLoader
     * @param SegmentHoldLoader $holdLoader
     * @param SegmentHoldWriter $holdWriter
     * @param RefundLedger $refundLedger
     * @param CreditMemoRefundReader $creditMemoReader
     * @param SegmentLoader $segmentLoader
     * @param SplitSegmentResource $segmentResource
     * @param SellerOrderStatus $sellerOrderStatus
     * @param ReleaseRaceGuard $raceGuard
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuSplitClient $gateway,
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly SegmentEligibility $eligibility,
        private readonly SegmentItemLoader $itemLoader,
        private readonly SegmentHoldLoader $holdLoader,
        private readonly SegmentHoldWriter $holdWriter,
        private readonly RefundLedger $refundLedger,
        private readonly CreditMemoRefundReader $creditMemoReader,
        private readonly SegmentLoader $segmentLoader,
        private readonly SplitSegmentResource $segmentResource,
        private readonly SellerOrderStatus $sellerOrderStatus,
        private readonly ReleaseRaceGuard $raceGuard,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param SplitSegment $segment
     * @return void
     */
    public function release(SplitSegment $segment): void
    {
        $segmentId = (int) $segment->getId();

        // Idempotency: a segment already requested/released is never re-fired by this scan
        // (the recon path owns RELEASE_REQUESTED → RELEASED).
        if (in_array($segment->getState(), [SplitSegment::STATE_RELEASE_REQUESTED, SplitSegment::STATE_RELEASED], true)) {
            return;
        }

        $header = $this->repository->getByOrderId($segment->getOrderId());
        $mihpayid = $header !== null ? (string) $header->getMihpayid() : '';
        if ($mihpayid === '') {
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: cannot release segment %d — order %d has no mihpayid yet.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        $childMid = $segment->getChildMid();
        if ($childMid === null) {
            // An unmapped/missing child MID means we cannot target the release. Surface it.
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: cannot release segment %d (order %d, seller %d) — no child MID mapped.',
                $segmentId,
                $segment->getOrderId(),
                $segment->getSellerId()
            ));
            return;
        }

        if (!$this->isReleasable($segment)) {
            return;
        }

        // Captured before the claim so a post-claim race (cancel/return/refund landing between the
        // pre-claim gate and this claim) can be rolled back to exactly the state we found.
        $priorState = $segment->getState();

        // Write-ahead AS AN ATOMIC CLAIM (CAS): flip ELIGIBLE→RELEASE_REQUESTED + bump attempts
        // in one conditional UPDATE so two overlapping cron ticks cannot both fire a release for
        // the same segment (release_settlement has no amount param — it releases the whole held
        // balance, so a double-fire is a double-release; we must not rely on PayU's unverified
        // concurrent dedup alone). 0 rows affected ⇒ another run already claimed it ⇒ bail before
        // firing. This also persists the intent BEFORE the gateway call (crash-recovery: a crash
        // mid-call leaves a recoverable RELEASE_REQUESTED record the recon readback resolves).
        $connection = $this->segmentResource->getConnection();
        $claimed = (int) $connection->update(
            $this->segmentResource->getMainTable(),
            [
                'state' => SplitSegment::STATE_RELEASE_REQUESTED,
                // Strand-age anchor for the A5c-5 recon: the instant we requested the release,
                // so a segment that never reconciles to RELEASED can be alarmed by age.
                'release_requested_at' => $this->dateTime->gmtDate(),
                'release_attempts' => new Expression('release_attempts + 1'),
            ],
            [
                'segment_id = ?' => $segmentId,
                'state NOT IN (?)' => [SplitSegment::STATE_RELEASE_REQUESTED, SplitSegment::STATE_RELEASED],
            ]
        );
        if ($claimed === 0) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d already claimed for release by a concurrent run — not re-firing.',
                $segmentId
            ));
            return;
        }
        // Reflect the claimed state on the in-memory model for callers/logging.
        $segment->setState(SplitSegment::STATE_RELEASE_REQUESTED);

        // Close the scan→claim TOCTOU (gate blocker #1). The pre-claim gate read its signals
        // BEFORE this claim acquired the row; a cancel/return/refund that committed in that window
        // (placing a hold, flipping marketplace_orders.is_canceled, or opening a refund leg) must
        // abort the release. Re-read ONLY those time-flippable signals now and, if any tripped,
        // undo the claim — restoring the prior state, nulling the request stamp, and decrementing
        // the attempt we just added — so the segment is left exactly as found for the refund
        // executor (or a later release) to take. A cancel that lands AFTER we fire is a
        // released-then-cancelled case = clawback (A8), out of scope here.
        if ($this->racedIntoIneligibility($segment)) {
            $rolledBack = (int) $connection->update(
                $this->segmentResource->getMainTable(),
                [
                    'state' => $priorState,
                    'release_requested_at' => null,
                    // The decrement is always paired with this call's own claim +1, but floor at 0
                    // so no future refactor can underflow the unsigned column (money-review hardening).
                    'release_attempts' => new Expression('GREATEST(release_attempts - 1, 0)'),
                ],
                [
                    'segment_id = ?' => $segmentId,
                    'state = ?' => SplitSegment::STATE_RELEASE_REQUESTED,
                ]
            );
            // Mirror the rollback on the in-memory model only if the conditional UPDATE actually
            // restored the row (1 affected). A 0-row result means another path already moved it
            // (e.g. recon) — don't let the model claim a prior state the DB no longer holds.
            if ($rolledBack === 1) {
                $segment->setState($priorState);
            }
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d became ineligible after claim (cancel/return/refund raced in) '
                . '— claim rolled back, not firing.',
                $segmentId
            ));
            return;
        }

        try {
            $response = $this->gateway->releaseSettlement($mihpayid, $childMid);
            if ($response->isAlreadyReleased()) {
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: release for segment %d already accepted by PayU (idempotent).',
                    $segmentId
                ));
            } else {
                // Sync response is not authoritative (async settlement); the recon readback
                // (INV-18) promotes RELEASE_REQUESTED → RELEASED once the amount actually settles.
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: release requested for segment %d (mid %s); awaiting settlement readback.',
                    $segmentId,
                    $childMid
                ));
            }
        } catch (PayuSplitRequestException $e) {
            // Transport failure — the write-ahead RELEASE_REQUESTED is the durable record. Recovery
            // is automatic: once the release is OVERDUE (past the settlement grace) with no
            // settlement signal, the recon path (A5c-5, ReleaseReconciler::handleNoSignal) re-fires
            // it, bounded by release_attempts (PayU dedups an already-accepted release, so a re-fire
            // is safe). If it still never settles, recon raises the strand alarm once it has sat
            // past the alert threshold (age-based). The main release scan won't re-select it
            // (RELEASE_REQUESTED is excluded) — log CRITICAL so it is queryable meanwhile. Never
            // rethrow: the scan isolates per segment regardless.
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: release transport error for segment %d — left RELEASE_REQUESTED; recon will re-fire — %s',
                $segmentId,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param SplitSegment $segment
     * @return bool
     */
    private function isReleasable(SplitSegment $segment): bool
    {
        $segmentId = (int) $segment->getId();

        if ($segment->getSegmentType() === SplitSegment::TYPE_COMMISSION) {
            return $this->isParentReleasable($segment);
        }

        // A cancelled seller portion (marketplace_orders.is_canceled = 1) makes this a REFUND
        // candidate, never a release candidate — releasing it would strand the customer's refund
        // (RefundExecutor permanently refuses an already-released segment). Keep the release gate
        // consistent with RefundableSegments, which selects on this same flag (gate blocker #1).
        // VENDOR segments only: the parent commission is governed by parent-last + the var8
        // aggregatorRefundAmount deducting cancelled commission from its held pool, so blocking
        // the parent on is_canceled (a flag that never clears) would permanently strand it.
        if ($this->sellerOrderStatus->isSellerCanceled((int) $segment->getOrderId(), (int) $segment->getSellerId())) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d not releasable — seller %d portion of order %d is cancelled '
                . '(refund candidate, not a release candidate).',
                $segmentId,
                (int) $segment->getSellerId(),
                (int) $segment->getOrderId()
            ));
            return false;
        }

        $gates = [];
        $orderItemIds = [];
        foreach ($this->itemLoader->loadItems($segmentId) as $item) {
            $gates[] = $item->toGate();
            $orderItemId = $item->getOrderItemId();
            if ($orderItemId > 0) {
                $orderItemIds[] = $orderItemId;
            }
        }
        $result = $this->eligibility->evaluate(
            $segment->getState(),
            $gates,
            $this->holdLoader->holdTypes($segmentId),
            // #12: only a release-BLOCKING refund leg (PENDING/QUEUED/HELD) defers release — a
            // completed SUCCESS refund lets the remainder go (the money already left PayU's escrow).
            $this->refundLedger->releaseBlockingLegs($segmentId) !== [],
            $this->dateTime->gmtDate()
        );
        if (!$result->eligible) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d not yet releasable — %s.',
                $segmentId,
                (string) $result->reason
            ));
            return false;
        }

        // Gate item #5: the segment is otherwise fully releasable, so the only thing that can still
        // make releasing it wrong is an OUT-OF-BAND Magento credit memo. Customer refunds on a split
        // order run through the var8 split-refund path (which bumps refunded_paise and never writes a
        // Magento credit memo), so a non-zero base_amount_refunded means the customer was already
        // refunded OUTSIDE the split engine. release_settlement disburses the whole held submerchant
        // balance (no amount param), so releasing would over-pay the vendor relative to the customer's
        // true net payment and drift the Webkul ledger. The readback recon (INV-18) cannot catch an
        // OFFLINE credit memo (PayU still holds the full amount → settled == amount − refunded_paise),
        // so the gate must stop here: FREEZE (durable hold + CRITICAL alarm) for a human to reconcile
        // (issue the var8 / clear the hold). The FROZEN hold drops the segment from the release scan's
        // hold anti-join, so this fires at most once.
        // Per-item base_amount_refunded (vendor-attributable) PLUS the order-level out-of-band
        // refund (adjustment/shipping credit memo, F3) that the per-item read misses.
        $creditMemoPaise = $this->creditMemoReader->refundedPaiseForItems($orderItemIds)
            + $this->creditMemoReader->orderLevelRefundPaise((int) $segment->getOrderId());
        if ($creditMemoPaise > 0) {
            $this->holdWriter->place(
                $segmentId,
                SplitHold::TYPE_FROZEN,
                sprintf(
                    'Out-of-band Magento credit memo (%d paise) not reconciled into the split — release '
                    . 'frozen to avoid over-paying the vendor (gate item #5).',
                    $creditMemoPaise
                )
            );
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: segment %d (order %d, seller %d) FROZEN — %d paise refunded out-of-band via a '
                . 'Magento credit memo (not through the split var8). Manual reconciliation required.',
                $segmentId,
                (int) $segment->getOrderId(),
                (int) $segment->getSellerId(),
                $creditMemoPaise
            ));
            return false;
        }

        return true;
    }

    /**
     * The post-claim TOCTOU re-check, delegated to the shared {@see ReleaseRaceGuard} so this site
     * and {@see ReleaseReconciler}'s overdue re-fire cannot drift (gate item F2). Re-reads only the
     * signals that can flip between the pre-claim gate and the claim — a hold, an in-flight refund
     * leg (INV-19), a just-opened return, an out-of-band credit memo, or a seller-portion cancel
     * (vendor only). If any tripped, the caller rolls the claim back rather than firing.
     *
     * @param SplitSegment $segment
     * @return bool true if a race made the segment ineligible after it was claimed
     */
    private function racedIntoIneligibility(SplitSegment $segment): bool
    {
        return $this->raceGuard->racedIntoIneligibility($segment);
    }

    /**
     * Parent-last (INV-14): the commission segment releases only once EVERY vendor segment of
     * the order is terminal, and the parent itself is unheld and free of an in-flight refund.
     *
     * @param SplitSegment $parent
     * @return bool
     */
    private function isParentReleasable(SplitSegment $parent): bool
    {
        $segmentId = (int) $parent->getId();

        if ($this->holdLoader->holdTypes($segmentId) !== []) {
            $this->logger->info(sprintf('RKD_PayuSplit: parent segment %d is held — release deferred.', $segmentId));
            return false;
        }
        // #12: a completed SUCCESS commission refund no longer defers the parent's remainder release;
        // only an in-flight one (PENDING/QUEUED/HELD) does.
        if ($this->refundLedger->releaseBlockingLegs($segmentId) !== []) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: parent segment %d has an in-flight commission refund — release deferred (INV-19).',
                $segmentId
            ));
            return false;
        }

        // F3 (parent #5): an out-of-band order-level refund (adjustment/shipping credit memo) the
        // split engine never processed. The parent has no items, so only the order-level read applies.
        // release_settlement disburses the whole held commission+shipping pool, so releasing over an
        // unreconciled refund would over-pay. FREEZE (durable hold + CRITICAL), never release. This is
        // reachable only once O-13 wires the parent's child_mid; harmless (0-paise no-op) before then.
        $creditMemoPaise = $this->creditMemoReader->orderLevelRefundPaise((int) $parent->getOrderId());
        if ($creditMemoPaise > 0) {
            $this->holdWriter->place(
                $segmentId,
                SplitHold::TYPE_FROZEN,
                sprintf(
                    'Out-of-band order-level refund (%d paise, adjustment/shipping credit memo) not '
                    . 'reconciled into the split — parent release frozen (gate item #5/F3).',
                    $creditMemoPaise
                )
            );
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: parent segment %d (order %d) FROZEN — %d paise refunded out-of-band via a '
                . 'Magento credit memo (adjustment/shipping). Manual reconciliation required.',
                $segmentId,
                (int) $parent->getOrderId(),
                $creditMemoPaise
            ));
            return false;
        }

        foreach ($this->segmentLoader->loadByOrderId($parent->getOrderId()) as $sibling) {
            if ($sibling->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
                continue;
            }
            if (!in_array($sibling->getState(), SplitSegment::TERMINAL_STATES, true)) {
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: parent segment %d waits — vendor segment %d is %s (not terminal). INV-14.',
                    $segmentId,
                    (int) $sibling->getId(),
                    $sibling->getState()
                ));
                return false;
            }
        }
        return true;
    }
}
