<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Exception\RefundCalculationException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\RefundLeg;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\FullUnwindResult;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;

/**
 * Issues a customer refund (var8 / cancel_refund_transaction) for one cancelled vendor
 * segment on an already-split order, and records the two proven legs (E-12) onto the
 * refund ledger. The money mover of slice A5b.
 *
 * Guards (never move money on bad state):
 *   - vendor segments only (the parent's commission is refunded as the agg leg of a
 *     vendor refund, never directly);
 *   - ledger-presence idempotency — a non-FAILED leg means the refund is already in
 *     flight or done, so never fire a second var8;
 *   - Fact 4 — the split header must be DONE before any var8 (refund-before-split is
 *     unproven and unsafe); no mihpayid / no child key → defer.
 *
 * Crash safety (token-primary, per the locked recovery decision):
 *   - write-ahead REFUND_PENDING + a deterministic var2 token (R-{segmentId}-{seq})
 *     before the POST, reused verbatim on a retry so PayU's var2-uniqueness dedups a
 *     duplicate (the get_aggregator_transactions reconciliation is a separate job);
 *   - a transport failure leaves the segment REFUND_PENDING and writes NO leg — the POST
 *     may have landed, so the next run retries with the same token rather than
 *     double-refunding.
 *
 * Attribution (INV-10): the child leg debits this vendor's segment; the parent commission
 * leg debits the parent (seller 0) segment. Each segment's refunded_paise is bumped by its
 * own leg, so INV-7 stays a clean per-segment sum.
 */
class RefundExecutor
{
    /**
     * A refund PayU rejects this many times is treated as permanently stuck: the executor
     * stops re-firing it and freezes the segment (HELD) for manual reconciliation. At the
     * 15-minute refund cron, 5 attempts ≈ 75 minutes of automatic retries before giving up —
     * enough to ride out a transient PayU rejection, bounded enough never to loop forever.
     *
     * (A PayU-confirmed terminal-error-code fast-path could pre-empt the cap on a permanent
     * rejection, but PayU's refund error codes are undocumented — the open duplicate-var2-on-
     * rejection question, H-6. Until answered, the cap is the loop-breaker.)
     */
    public const MAX_REFUND_ATTEMPTS = 5;

    /**
     * @param PayuSplitClient $gateway
     * @param RefundCalculator $calculator
     * @param RefundResponseParser $responseParser
     * @param PaidAmountReader $paidReader
     * @param SegmentItemLoader $itemLoader
     * @param SegmentLoader $segmentLoader
     * @param RefundLedger $ledger
     * @param SplitOrderRepositoryInterface $repository
     * @param SplitSegmentResource $segmentResource
     * @param SplitConfig $config
     * @param FullUnwindDetector $fullUnwindDetector
     * @param SegmentHoldLoader $holdLoader
     * @param SegmentHoldWriter $holdWriter
     * @param CreditMemoRefundReader $creditMemoReader
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuSplitClient $gateway,
        private readonly RefundCalculator $calculator,
        private readonly RefundResponseParser $responseParser,
        private readonly PaidAmountReader $paidReader,
        private readonly SegmentItemLoader $itemLoader,
        private readonly SegmentLoader $segmentLoader,
        private readonly RefundLedger $ledger,
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly SplitSegmentResource $segmentResource,
        private readonly SplitConfig $config,
        private readonly FullUnwindDetector $fullUnwindDetector,
        private readonly SegmentHoldLoader $holdLoader,
        private readonly SegmentHoldWriter $holdWriter,
        private readonly CreditMemoRefundReader $creditMemoReader,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Refund the customer for one cancelled vendor segment.
     *
     * @param SplitSegment $segment The vendor segment to refund
     * @param string $reason One of SplitRefund::REASON_*
     * @return void
     */
    public function execute(SplitSegment $segment, string $reason): void
    {
        if ($segment->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
            return; // the parent commission is only ever refunded as a vendor refund's agg leg
        }
        if ($segment->getState() === SplitSegment::STATE_REFUNDED) {
            return; // already fully refunded
        }

        $segmentId = (int) $segment->getId();
        $orderId = $segment->getOrderId();

        // F6: a FROZEN/MANUAL hold HALTS auto-processing for this segment. The refund reconciler
        // (gate item #6) FROZEN-holds a segment whose refund leg FAILED at PayU after acceptance —
        // refunded_paise was optimistically bumped but the customer was NOT refunded, so the books
        // are known-inconsistent and a re-fired var8 would double-refund off a corrupt base.
        // RefundableSegments now anti-joins these holds so the scan never re-selects a frozen
        // segment; guard here too so a directly-passed segment (a future caller, or a hold placed
        // in the scan→execute window) is refused the same way. CANCELED/RETURN_OPEN are deliberately
        // NOT blocking (SplitHold::REFUND_BLOCKING_TYPES) — a CANCELED hold IS the refund trigger,
        // so blocking on it would strand the customer's money.
        $blockingHolds = array_intersect(
            $this->holdLoader->holdTypes($segmentId),
            SplitHold::REFUND_BLOCKING_TYPES
        );
        if ($blockingHolds !== []) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: refund for segment %d (order %d) skipped — segment is held [%s]; '
                . 'auto-processing halted (F6).',
                $segmentId,
                $orderId,
                implode(', ', $blockingHolds)
            ));
            return;
        }

        // Never refund a segment whose escrow has already been released (or is on the
        // release track / written off). Its V_v has left PayU, so a var8 against it would
        // refund money the marketplace no longer holds (over-refund). The release policy
        // (release only after delivery + window close) makes this rare, but money code must
        // not rely on an upstream policy holding.
        if ($segment->getReleasedPaise() > 0
            || in_array(
                $segment->getState(),
                [
                    SplitSegment::STATE_RELEASE_REQUESTED,
                    SplitSegment::STATE_RELEASED,
                    SplitSegment::STATE_WRITTEN_OFF,
                ],
                true
            )
        ) {
            $this->logger->error(sprintf(
                'RKD_PayuSplit: refund for segment %d REFUSED — released/terminal (state %s, released %d paise); '
                . 'refunding released escrow would over-refund. Reconcile manually.',
                $segmentId,
                $segment->getState(),
                $segment->getReleasedPaise()
            ));
            return;
        }

        // Idempotency: a non-FAILED leg means this refund is already in flight or done.
        if ($this->ledger->activeLegs($segmentId) !== []) {
            return;
        }

        // R-6 retry-cap: a refund PayU has rejected MAX_REFUND_ATTEMPTS times (one FAILED leg
        // per rejected tick) is treated as permanently stuck. Re-firing it would loop forever
        // AND keep hammering PayU, while the customer is never refunded. Stop: freeze the
        // segment off the scan with a HELD marker + alarm for manual reconciliation, and send
        // no further var8. Checked before the PayU call so the cap bounds the call count.
        if ($this->ledger->failedLegCount($segmentId) >= self::MAX_REFUND_ATTEMPTS) {
            $this->freezeSegment($orderId, $segmentId, $reason, sprintf(
                'PayU rejected the refund %d times (retry cap reached)',
                self::MAX_REFUND_ATTEMPTS
            ));
            return;
        }

        // Fact 4: a var8 is only valid against a transaction that is already split.
        $header = $this->repository->getByOrderId($orderId);
        if ($header === null || $header->getSplitState() !== SplitOrder::STATE_DONE) {
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: refund for segment %d (order %d) blocked — split is not DONE (Fact 4).',
                $segmentId,
                $orderId
            ));
            return;
        }

        $mihpayid = $header->getMihpayid();
        if ($mihpayid === null) {
            $this->logger->warning(sprintf('RKD_PayuSplit: refund for segment %d deferred — no mihpayid.', $segmentId));
            return;
        }

        $childKey = $segment->getChildMerchantKey();
        if ($childKey === null) {
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: refund for segment %d skipped — vendor has no PayU child key.',
                $segmentId
            ));
            return;
        }

        // A vendor child key that equals the parent aggregator key is a mis-mapped
        // submerchant: findLeg() would then match the SAME response leg for both child and
        // parent, mis-attributing the debit. Refuse rather than mis-account.
        if ($childKey === $this->config->getMerchantKey()) {
            $this->logger->error(sprintf(
                'RKD_PayuSplit: refund for segment %d REFUSED — vendor child key equals the parent '
                . 'aggregator key (mis-mapped submerchant). Fix the submerchant mapping.',
                $segmentId
            ));
            return;
        }

        // Load every segment on the order once: find the parent (for the agg-leg bound and
        // the full-unwind shipping check) and keep the vendor siblings for the post-refund
        // full-unwind detection.
        $allSegments = $this->segmentLoader->loadByOrderId($orderId);
        $parentSegment = $this->findParentSegment($allSegments);
        $parentRemaining = $parentSegment === null
            ? 0
            : $parentSegment->getAmountPaise() - $parentSegment->getRefundedPaise();

        $orderItemIds = $this->itemLoader->loadOrderItemIds($segmentId);

        // F5: an out-of-band Magento credit memo means the customer was already (partially) refunded
        // OUTSIDE the split engine (on a split order, customer refunds run through the var8 path,
        // which bumps refunded_paise and never writes a credit memo — so any credit memo here is
        // out-of-band). We must NOT net it into the var8: Magento writes base_amount_refunded
        // tax-EXCLUSIVE (tax → base_tax_refunded, discount → base_discount_refunded), so a
        // fully-memo'd taxed line still nets to its GST residual, and the var8 would re-refund that
        // GST — over-refund release_settlement can't claw back. Mirror the release-side #5/F3 posture
        // exactly: FREEZE (durable TYPE_FROZEN hold + CRITICAL), never net. Per-item
        // (vendor-attributable) PLUS the order-level adjustment/shipping memo the per-item read
        // misses (F3). Composes with F6 — the FROZEN hold drops the segment from the scan + the
        // in-executor guard, so this fires at most once.
        $creditMemoPaise = $this->creditMemoReader->refundedPaiseForItems($orderItemIds)
            + $this->creditMemoReader->orderLevelRefundPaise($orderId);
        if ($creditMemoPaise > 0) {
            $this->freezeCreditMemo($segmentId, $orderId, $creditMemoPaise);
            return;
        }

        $paidPaise = $this->paidReader->paidPaiseForItems($orderItemIds);
        if ($paidPaise === 0) {
            // A fully-comped line: the customer paid nothing for it, so there is nothing to refund
            // via a var8. Terminalise the segment (no var8, no child/parent leg) so it does not
            // loop on the scan. BUT this comped segment can be the LAST vendor to terminalise,
            // completing a full unwind — in which case the customer is still owed the retained
            // shipping (gate blocker #8). Record that obligation here too, in the SAME atomic
            // discipline as the var8 path (H-3), or a comped segment closing the unwind would
            // silently strand the shipping AND let the parent over-release it. No var8 fires, so
            // the parent balance is unchanged → parentRemainingAfter == parentRemaining.
            $connection = $this->segmentResource->getConnection();
            $connection->beginTransaction();
            $compedTopUp = 0;
            $compedShortfall = 0;
            try {
                $segment->setState(SplitSegment::STATE_REFUNDED);
                $segment->setData('intent_token', null);
                $this->segmentResource->save($segment);

                $unwind = $this->recordFullUnwindIfComplete(
                    $allSegments,
                    $segmentId,
                    $parentRemaining,
                    (int) $header->getShippingPaise(),
                    $orderId,
                    $parentSegment
                );
                $compedTopUp = $unwind?->topUpPaise ?? 0;
                $compedShortfall = $unwind?->shortfallPaise ?? 0;

                $connection->commit();
            } catch (\Throwable $t) {
                $connection->rollBack();
                $this->logger->error(sprintf(
                    'RKD_PayuSplit: comped-segment bookkeeping failed for segment %d (no var8 fired) — %s',
                    $segmentId,
                    $t->getMessage()
                ));
                return;
            }

            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d had no customer-paid amount (fully comped) — marked REFUNDED, no var8.',
                $segmentId
            ));
            $this->alarmFullUnwind($orderId, $compedTopUp, $compedShortfall);
            $this->dispatchRefunded($segment);
            return;
        }
        if ($paidPaise < 0) {
            // Negative means Magento over-allocated discount to the row
            // (base_discount_amount > base_row_total_incl_tax). Never refund < 0. This is a
            // data error that will not self-resolve, so FREEZE (HELD marker + alarm) rather
            // than bare-return — a bare return leaves the segment on the scan to be re-selected
            // every tick (B1 silent loop, no queryable row). The HELD leg makes the owed line
            // queryable and takes it off the scan until a human fixes the discount allocation.
            $this->freezeSegment($orderId, $segmentId, $reason, sprintf(
                'negative customer-paid amount (%d paise) — check Magento discount allocation',
                $paidPaise
            ));
            return;
        }

        try {
            $plan = $this->calculator->calculate(
                $childKey,
                $segment->getAmountPaise(),
                $segment->getRefundedPaise(),
                $paidPaise,
                $parentRemaining
            );
        } catch (RefundCalculationException $e) {
            // The refund math does not close (the figure exceeds the fundable segment + parent
            // balance, or the snapshot is corrupt). Retrying an unfundable refund every tick is
            // a B1 silent loop that never refunds the customer — FREEZE (HELD + alarm) so the
            // stuck refund is queryable and off the scan for a human to reconcile.
            $this->freezeSegment($orderId, $segmentId, $reason, sprintf(
                'refund not computable — %s',
                $e->getMessage()
            ));
            return;
        }

        $var8Request = $this->calculator->buildVar8Request($plan);
        $token = $this->resolveToken($segment, $segmentId);

        // R-1: write-ahead AS AN OPTIMISTIC-CAS CLAIM. A conditional UPDATE that flips the segment to
        // REFUND_PENDING + stores the token, gated on the refund_attempts version we read — so two
        // overlapping cron ticks that both passed the activeLegs() read cannot both fire a var8 for
        // this segment (the deterministic token means a double-fire double-bumps refunded_paise AND
        // double-refunds the customer; we must not lean on PayU's unverified var2 dedup alone, H-6).
        // 0 rows ⇒ another run already claimed it ⇒ bail BEFORE the POST. A sequential transport-
        // failure retry reads the bumped version and re-claims, so crash recovery still works. Being
        // a targeted UPDATE (not a full-row save) it also cannot clobber refunded_paise (R-3). This
        // persists the intent BEFORE the gateway call (a crash mid-POST leaves a recoverable
        // REFUND_PENDING + token for the next run's re-fire).
        $observedAttempts = $segment->getRefundAttempts();
        $claimed = (int) $this->segmentResource->getConnection()->update(
            $this->segmentResource->getMainTable(),
            [
                'state' => SplitSegment::STATE_REFUND_PENDING,
                'intent_token' => $token,
                'refund_attempts' => new Expression('refund_attempts + 1'),
            ],
            [
                'segment_id = ?' => $segmentId,
                'refund_attempts = ?' => $observedAttempts,
            ]
        );
        if ($claimed === 0) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d already claimed for refund by a concurrent run — not re-firing.',
                $segmentId
            ));
            return;
        }
        // Reflect the claimed state on the in-memory model for the bookkeeping + logging below.
        $segment->setState(SplitSegment::STATE_REFUND_PENDING);
        $segment->setData('intent_token', $token);
        $segment->setData('refund_attempts', $observedAttempts + 1);

        try {
            $response = $this->gateway->cancelRefundTransaction(
                $mihpayid,
                $token,
                $var8Request->totalRupees(),
                $var8Request->json()
            );
        } catch (PayuSplitRequestException $e) {
            // The POST may have landed though the response was lost — leave REFUND_PENDING
            // with the deterministic token so the next run retries safely, never a leg.
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: transport error refunding segment %d — retry next run: %s',
                $segmentId,
                $e->getMessage()
            ));
            return;
        }

        $raw = $response->raw();
        $legs = $this->responseParser->parseLegs($raw);

        // R-7: the only merchant keys a child-keyed var8 may legitimately return are this
        // vendor's child key and the parent aggregator key (E-12). A key we never targeted is
        // a real PayU debit we cannot attribute, and the normal bookkeeping below would
        // silently ignore it. The check reads the RAW body (not the parsed legs): the parser
        // drops non-string keys, and a stray debit returned under a numeric merchant key is
        // coerced by json_decode to an int key — so it would otherwise vanish before this
        // check and slip through. Halt: freeze the segment off the retry surface (so the scan
        // does not re-fire an anomalous transaction → double-refund) and alarm for manual
        // reconciliation against PayU's actually-returned legs.
        $anomalies = $this->findResponseAnomalies($raw, $childKey);
        if ($anomalies !== []) {
            $this->freezeSegment($orderId, $segmentId, $reason, sprintf(
                'response carried unexpected/duplicate merchant legs [%s] — expected only '
                . 'child %s + parent %s (var8 already fired, token %s)',
                implode(', ', $anomalies),
                $childKey,
                $this->config->getMerchantKey(),
                $token
            ));
            return;
        }

        $childLeg = $this->findLeg($legs, $childKey);
        $parentLeg = $this->findLeg($legs, $this->config->getMerchantKey());

        // PayU processes each merchant key in the var8 independently, so the child and the
        // parent commission legs can land with different outcomes. Each is recorded and
        // bumped by ITS OWN acceptance — never bump a segment for a leg PayU did not confirm.
        $childAccepted = $childLeg !== null && $childLeg->isAccepted();
        $parentExpected = $plan->aggregatorPaise > 0 && $parentSegment !== null;
        $parentAccepted = $parentLeg !== null && $parentLeg->isAccepted();

        // F7: PayU signalled acceptance (status:1) but issued NO request_id for an accepted leg — the
        // poll/idempotency anchor is missing. This happens on a var2-dedup echo of a token retry
        // (H-6: "already accepted" without a fresh request_id). Recording such a leg QUEUED would bump
        // refunded_paise for a leg the reconciler can NEVER poll (RefundLegLoader excludes
        // null-request-id) and NEVER age-alarm (RefundReconciler early-returns on null request_id) —
        // a silent strand that still blocks the segment's release. And it is NOT a rejection: PayU
        // DID accept, so re-firing risks double-refunding a real debit. Halt on either leg: freeze
        // the segment (HELD marker, no bump, no QUEUED record) and reconcile against PayU by hand.
        // Conservative by design — the whole response is discarded even if only one leg is anchorless
        // (the H-6 echo leaves both anchorless anyway; a genuine fresh accept always carries an id).
        $childAnchorless = $childAccepted && $childLeg->requestId === null;
        $parentAnchorless = $parentExpected && $parentAccepted && $parentLeg->requestId === null;
        if ($childAnchorless || $parentAnchorless) {
            $this->freezeSegment($orderId, $segmentId, $reason, sprintf(
                'PayU accepted a refund leg with NO request_id (child %s, parent %s) — not pollable, '
                . 'cannot confirm; var8 already fired (token %s). Frozen to avoid a silent strand or a '
                . 're-fire double-refund',
                $childAnchorless ? 'ANCHORLESS' : 'ok',
                $parentAnchorless ? 'ANCHORLESS' : 'ok',
                $token
            ));
            return;
        }

        // All bookkeeping for one var8 response is atomic (H-3): a crash must never leave the
        // child leg written but the parent leg dropped (which would later over-refund). The
        // var8 has already fired, so a rollback is safe — the next run re-fires with the same
        // deterministic token and PayU dedups via var2 uniqueness.
        $connection = $this->segmentResource->getConnection();
        $connection->beginTransaction();
        $fullUnwindTopUp = 0;
        $fullUnwindShortfall = 0;
        try {
            if ($childAccepted) {
                $this->ledger->recordLeg(
                    $orderId,
                    $segmentId,
                    $plan->amountPaise,
                    0,
                    $childLeg->requestId,
                    SplitRefund::STATE_QUEUED,
                    $reason
                );
                $this->bumpSegment($segment, $plan->amountPaise);
                $segment->setData('intent_token', null);
                $this->segmentResource->save($segment);
            } else {
                // Rejected child — FAILED leg, no bump, segment stays REFUND_PENDING.
                // v1 gap (accepted): a FAILED leg is not "active", so the scan re-selects and
                // this retries every tick — correct for a transient rejection, an infinite
                // loop for a permanent one. The v2 fix is a terminal error-code gate
                // (mirroring SplitRequester::TERMINAL_FAILURE_CODES) + the recon retry-cap.
                $this->ledger->recordLeg(
                    $orderId,
                    $segmentId,
                    $plan->amountPaise,
                    0,
                    $childLeg?->requestId,
                    SplitRefund::STATE_FAILED,
                    $reason
                );
            }

            if ($parentExpected) {
                if ($parentAccepted) {
                    $this->ledger->recordLeg(
                        $orderId,
                        (int) $parentSegment->getId(),
                        0,
                        $plan->aggregatorPaise,
                        $parentLeg->requestId,
                        SplitRefund::STATE_QUEUED,
                        $reason
                    );
                    $this->bumpParentRefund($parentSegment, $plan->aggregatorPaise);
                } else {
                    // Unconfirmed commission leg — never bump the parent. R-5: when the CHILD
                    // was accepted, the vendor segment now has a QUEUED leg and is excluded
                    // from the scan, so the owed commission would have NO retry surface — a
                    // FAILED row there is permanently under-refunded. Record it PENDING instead
                    // (a queryable recon/retry obligation). When the child was REJECTED, the
                    // whole var8 re-fires via the vendor segment and produces a fresh parent
                    // leg, so this attempt's parent leg is a dead historical FAILED row.
                    $this->ledger->recordLeg(
                        $orderId,
                        (int) $parentSegment->getId(),
                        0,
                        $plan->aggregatorPaise,
                        $parentLeg?->requestId,
                        $childAccepted ? SplitRefund::STATE_PENDING : SplitRefund::STATE_FAILED,
                        $reason
                    );
                }
            }

            // R-4: the O-9 full-unwind shipping obligation is part of the SAME atomic
            // bookkeeping. Writing it before commit means a crash can never leave the refund
            // legs committed but the obligation (which holds the parent's release under
            // INV-19) lost — which would silently strand the customer's shipping. The CRITICAL
            // alarm for it is raised post-commit (an observation, not a write).
            if ($childAccepted) {
                $parentRemainingAfter = $parentRemaining - ($parentAccepted ? $plan->aggregatorPaise : 0);
                $unwind = $this->recordFullUnwindIfComplete(
                    $allSegments,
                    $segmentId,
                    $parentRemainingAfter,
                    (int) $header->getShippingPaise(),
                    $orderId,
                    $parentSegment
                );
                $fullUnwindTopUp = $unwind?->topUpPaise ?? 0;
                $fullUnwindShortfall = $unwind?->shortfallPaise ?? 0;
            }

            $connection->commit();
        } catch (\Throwable $t) {
            $connection->rollBack();
            $this->logger->error(sprintf(
                'RKD_PayuSplit: refund bookkeeping failed for segment %d (var8 already fired; retry next run) — %s',
                $segmentId,
                $t->getMessage()
            ));
            return;
        }

        // Alarms (post-commit observations, not writes).
        if (!$childAccepted) {
            $this->logger->error(sprintf(
                'RKD_PayuSplit: child refund REJECTED for segment %d — %s',
                $segmentId,
                $childLeg === null ? 'no child leg in response' : $childLeg->message
            ));
        }
        if ($parentExpected && !$parentAccepted) {
            $this->logger->error(sprintf(
                'RKD_PayuSplit: parent commission leg (%d paise) missing/rejected for segment %d — '
                . 'parent NOT bumped; FAILED leg recorded.',
                $plan->aggregatorPaise,
                $segmentId
            ));
        }
        if ($parentExpected && $childAccepted !== $parentAccepted) {
            // One leg landed and the other did not — the customer is PARTIALLY refunded. The
            // books reflect reality (each leg by its own status), but this needs a human.
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: leg-acceptance MISMATCH on segment %d — child %s, parent %s; '
                . 'customer partially refunded, reconcile manually.',
                $segmentId,
                $childAccepted ? 'accepted' : 'rejected',
                $parentAccepted ? 'accepted' : 'rejected'
            ));
        }

        if ($childAccepted) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: refunded segment %d (order %d) — vendor %d + commission %d paise [%s].',
                $segmentId,
                $orderId,
                $plan->amountPaise,
                $parentAccepted ? $plan->aggregatorPaise : 0,
                $reason
            ));
        }

        // O-9 full-unwind shipping true-up alarms (post-commit observations): the obligation row
        // was already written inside the transaction above; here we just summon a human.
        $this->alarmFullUnwind($orderId, $fullUnwindTopUp, $fullUnwindShortfall);

        // Gate blocker #4: the vendor segment is now (partially) refunded → close it in Webkul's
        // ledger so its payable saleslist rows can never be paid out (the customer was refunded).
        // Only when the child leg was accepted — a rejected child leaves the segment REFUND_PENDING
        // with nothing refunded. Post-commit so a re-run never double-acts (the listener is
        // idempotent on webkul_synced_at).
        if ($childAccepted) {
            $this->dispatchRefunded($segment);
        }
    }

    /**
     * Dispatch rkd_payu_segment_refunded (gate blocker #4): a post-commit signal that this vendor
     * segment is now REFUNDED/PARTIAL_REFUNDED, so its Webkul seller ledger rows must be closed
     * (marked non-payable). Fired AFTER the bookkeeping commit so a crash/retry never double-acts —
     * the listener and its reconcile backstop are idempotent on webkul_synced_at.
     *
     * @param SplitSegment $segment
     * @return void
     */
    private function dispatchRefunded(SplitSegment $segment): void
    {
        $this->eventManager->dispatch('rkd_payu_segment_refunded', ['segment' => $segment]);
    }

    /**
     * Post-commit CRITICAL alarms for an O-9 full-unwind shipping obligation (an observation, not
     * a write — the PENDING obligation row was recorded inside the transaction). Shared by the
     * var8 path and the comped-segment path (gate blocker #8) so a full unwind is alarmed the same
     * way however the last vendor segment terminalised.
     *
     * @param int $orderId
     * @param int $topUpPaise Shipping the parent escrow can fund (recorded PENDING; var8 can't carry it)
     * @param int $shortfallPaise Customer-paid shipping the parent escrow CANNOT fund (operating-fund debt)
     * @return void
     */
    private function alarmFullUnwind(int $orderId, int $topUpPaise, int $shortfallPaise): void
    {
        if ($topUpPaise > 0) {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: order %d FULLY UNWOUND — customer owed %d paise shipping top-up (O-9). '
                . 'Recorded as a PENDING obligation; var8 cannot carry it (per-child agg cap) — '
                . 'firing awaits the PayU parent-refund mechanism. Parent release is held meanwhile.',
                $orderId,
                $topUpPaise
            ));
        }

        // O-9 shortfall: the parent escrow could not fund the whole customer-paid shipping (a
        // marketplace-funded discount was absorbed into it). The customer is still owed this from
        // operating funds (S-17, off-PayU) — surfaced, never silently under-refunded.
        if ($shortfallPaise > 0) {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: order %d FULLY UNWOUND — %d paise of customer-paid shipping CANNOT be funded '
                . 'from the parent escrow (absorbed marketplace discount). Operating-fund obligation owed '
                . 'to the customer (S-17); reconcile off-PayU — no var8 carries it.',
                $orderId,
                $shortfallPaise
            ));
        }
    }

    /**
     * Bump a segment's cumulative refunded total and move its money-state accordingly. Used for the
     * VENDOR segment, which is single-writer here (the R-1 CAS claim guarantees only one tick refunds
     * a given vendor segment), so an in-memory read-modify-write followed by the caller's save is
     * safe. The PARENT commission segment is SHARED across an order's vendors, so it must NOT use
     * this path — see bumpParentRefund.
     *
     * @param SplitSegment $segment
     * @param int $deltaPaise
     * @return void
     */
    private function bumpSegment(SplitSegment $segment, int $deltaPaise): void
    {
        $refunded = $segment->getRefundedPaise() + $deltaPaise;
        $segment->setData('refunded_paise', $refunded);
        $segment->setState(
            $refunded >= $segment->getAmountPaise()
                ? SplitSegment::STATE_REFUNDED
                : SplitSegment::STATE_PARTIAL_REFUNDED
        );
    }

    /**
     * R-2: bump the SHARED parent commission segment via an ATOMIC SQL increment, not a read-modify-
     * write save. Two vendors of the same order refunding (each records a commission leg against the
     * one parent segment) would otherwise each load their own in-memory parent copy and clobber the
     * other's bump — a lost update on the parent's cumulative refund (a conservation break). The
     * increment is atomic and row-locks the parent until commit; refunded_paise is the money source
     * of truth. The state label is derived (release eligibility reads the balance, not the label) and
     * set from the in-memory total — correct under the single-cron-process reality and self-correcting
     * otherwise. A direct, targeted UPDATE (no full-row model save) so it never clobbers other columns.
     *
     * @param SplitSegment $parent
     * @param int $deltaPaise
     * @return void
     */
    private function bumpParentRefund(SplitSegment $parent, int $deltaPaise): void
    {
        $refunded = $parent->getRefundedPaise() + $deltaPaise;
        $state = $refunded >= $parent->getAmountPaise()
            ? SplitSegment::STATE_REFUNDED
            : SplitSegment::STATE_PARTIAL_REFUNDED;

        $this->segmentResource->getConnection()->update(
            $this->segmentResource->getMainTable(),
            [
                'refunded_paise' => new Expression('refunded_paise + ' . $deltaPaise),
                'state' => $state,
            ],
            ['segment_id = ?' => (int) $parent->getId()]
        );

        // Reflect on the in-memory model for downstream logging/consistency.
        $parent->setData('refunded_paise', $refunded);
        $parent->setState($state);
    }

    /**
     * Reuse the write-ahead token on a retry (so PayU's var2-uniqueness dedups), else
     * mint a fresh deterministic one. Single refund per segment in the cancellation scope,
     * so the sequence is 1; returns (deferred) will increment it.
     *
     * @param SplitSegment $segment
     * @param int $segmentId
     * @return string
     */
    private function resolveToken(SplitSegment $segment, int $segmentId): string
    {
        $existing = $segment->getData('intent_token');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        return sprintf('R-%d-1', $segmentId);
    }

    /**
     * @param SplitSegment[] $allSegments
     * @return SplitSegment|null The marketplace parent (commission) segment, if present
     */
    private function findParentSegment(array $allSegments): ?SplitSegment
    {
        foreach ($allSegments as $segment) {
            if ($segment->getSegmentType() === SplitSegment::TYPE_COMMISSION) {
                return $segment;
            }
        }
        return null;
    }

    /**
     * After a vendor segment has been refunded, decide whether the order has now FULLY
     * unwound (every vendor segment cancelled/refunded). The just-refunded segment is
     * treated as terminal even though its in-memory state may not yet reflect the save.
     *
     * @param SplitSegment[] $allSegments
     * @param int $refundedSegmentId The segment just refunded (counted as terminal)
     * @param int $parentRemainingAfter Parent's unrefunded balance after this refund
     * @param int $shippingPaise Customer-paid shipping snapshotted at split (the amount owed on unwind)
     * @param int $orderId
     * @param SplitSegment|null $parentSegment
     * @return FullUnwindResult|null Unwind verdict (top-up + shortfall) for the caller's post-commit
     *             alarms, or null when no full unwind (this method only writes).
     */
    private function recordFullUnwindIfComplete(
        array $allSegments,
        int $refundedSegmentId,
        int $parentRemainingAfter,
        int $shippingPaise,
        int $orderId,
        ?SplitSegment $parentSegment
    ): ?FullUnwindResult {
        $vendorStates = [];
        foreach ($allSegments as $s) {
            if ($s->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
                continue;
            }
            $vendorStates[] = (int) $s->getId() === $refundedSegmentId
                ? SplitSegment::STATE_REFUNDED
                : $s->getState();
        }

        $unwind = $this->fullUnwindDetector->detect($vendorStates, $parentRemainingAfter, $shippingPaise);
        if (!$unwind->isFullUnwind || $parentSegment === null) {
            return null;
        }

        $parentId = (int) $parentSegment->getId();

        // M-1: serialize concurrent ticks that detect the SAME order's full unwind. Both the var8
        // and comped refund paths reach here inside their transaction, and the comped path does NOT
        // bump (and thus lock) the parent — so without this, two overlapping ticks would both read
        // "no FULL_UNWIND leg" and both write one (a doubled obligation that mis-holds the parent's
        // release and would double-refund once the parent-refund mechanism is wired). Take a row
        // lock on the parent segment BEFORE the idempotency re-check; it is held until the enclosing
        // transaction commits, so a second tick blocks here, then sees the committed obligation and
        // skips. The parent row is the stable mutex every FULL_UNWIND writer for this order shares.
        //
        // ⚠️ ORDERING INVARIANT (load-bearing): this FOR UPDATE must be the FIRST read-view-
        // establishing read in the enclosing transaction. Under InnoDB REPEATABLE READ the
        // consistent snapshot is born at the first NON-locking SELECT; placing any plain SELECT
        // before this lock would freeze the snapshot early, and the activeLegs() re-check below
        // would then read a stale view missing a concurrently-committed obligation → the doubled
        // write returns. Do NOT add a non-locking read before this point. (The locking read here is
        // exempt — it always reads the latest committed row.)
        $connection = $this->segmentResource->getConnection();
        $connection->fetchOne(
            $connection->select()
                ->from($this->segmentResource->getMainTable(), ['segment_id'])
                ->where('segment_id = ?', $parentId)
                ->forUpdate(true)
        );

        // Idempotent: only one shipping top-up obligation per order. Check specifically for
        // an existing FULL_UNWIND obligation — the parent's normal commission legs (from the
        // vendor refunds) are active too and must NOT be mistaken for the obligation.
        foreach ($this->ledger->activeLegs($parentId) as $existing) {
            if ($existing->getReason() === SplitRefund::REASON_FULL_UNWIND) {
                return null;
            }
        }

        // O-9 Option C: the order fully unwound, so the customer is owed the retained
        // shipping (the parent's remaining balance). ⚠️ The actual var8 CANNOT carry it —
        // PayU caps aggregatorRefundAmount per child at that child's commission, far below
        // the shipping, and there is no proven parent-only refund primitive (open PayU
        // question, same class as the duplicate-var2 question). So we RECORD the obligation
        // as a PENDING leg (queryable, and it blocks the parent's release via INV-19) — the
        // firing awaits the PayU shipping-refund-mechanism answer. This closes the
        // silent-strand gap without firing a call PayU would reject. The caller alarms.
        if ($unwind->topUpPaise > 0) {
            $this->ledger->recordLeg(
                $orderId,
                $parentId,
                0,
                $unwind->topUpPaise,
                null,
                SplitRefund::STATE_PENDING,
                SplitRefund::REASON_FULL_UNWIND
            );
        }
        return $unwind;
    }

    /**
     * R-7 anomaly detection. A child-keyed var8 may legitimately return only this vendor's
     * child key and the parent aggregator key (E-12). Any other top-level merchant leg is a
     * real PayU debit we cannot attribute.
     *
     * This inspects the RAW decoded body, NOT the parsed RefundLeg[]: the parser drops
     * non-string keys, and a debit returned under a numeric merchant key is coerced by
     * json_decode to an int key — so it would vanish before this check and slip through to
     * the normal bookkeeping (a silent strand). A wire-level duplicate key cannot reach here
     * either way: json_decode collapses duplicate object keys to the last occurrence before
     * we ever see the body, so the decoded map's keys are unique.
     *
     * @param mixed $raw The decoded PayU response body (per-merchant-key map)
     * @param string $childKey
     * @return string[] The offending merchant keys (empty when the response is well-formed)
     */
    private function findResponseAnomalies(mixed $raw, string $childKey): array
    {
        if (!is_array($raw)) {
            return []; // a non-array body has no merchant legs to mis-attribute (handled downstream)
        }
        $allowed = [$childKey, $this->config->getMerchantKey()];
        $anomalies = [];
        foreach ($raw as $key => $leg) {
            if (!is_array($leg)) {
                continue; // a top-level scalar (a global envelope field) is not a merchant leg
            }
            $key = (string) $key; // a numeric merchant key decodes to an int — still a merchant leg
            if (!in_array($key, $allowed, true)) {
                $anomalies[] = $key;
            }
        }
        return $anomalies;
    }

    /**
     * F5: freeze a refund blocked by an out-of-band Magento credit memo. Places the SAME durable
     * TYPE_FROZEN hold the release-side #5/F3 posture uses (so the #11 clear-hold break-glass, which
     * re-checks the credit-memo reader before clearing, resolves release-frozen and refund-frozen
     * segments uniformly) and raises a CRITICAL alarm. No var8 and no bump — a credit-memo'd amount
     * must be reconciled by a human, never re-refunded. Composes with F6: the FROZEN hold drops the
     * segment from the refund scan's anti-join and the in-executor guard, so this fires at most once.
     *
     * @param int $segmentId
     * @param int $orderId
     * @param int $creditMemoPaise
     * @return void
     */
    private function freezeCreditMemo(int $segmentId, int $orderId, int $creditMemoPaise): void
    {
        $this->holdWriter->place(
            $segmentId,
            SplitHold::TYPE_FROZEN,
            sprintf(
                'Out-of-band Magento credit memo (%d paise) not reconciled into the split — refund '
                . 'frozen to avoid re-refunding the credit-memo\'d amount (gate item F5).',
                $creditMemoPaise
            )
        );
        $this->logger->critical(sprintf(
            'RKD_PayuSplit: refund for segment %d (order %d) FROZEN — %d paise refunded out-of-band via a '
            . 'Magento credit memo (not through the split var8). Manual reconciliation required (F5).',
            $segmentId,
            $orderId,
            $creditMemoPaise
        ));
    }

    /**
     * Halt auto-processing for a segment and summon a human: record a HELD marker leg — which
     * is NON-FAILED, so the scan stops re-selecting the segment (no re-fire) and the
     * idempotency set is non-empty — and raise a CRITICAL alarm. Used when the executor cannot
     * safely continue: an anomalous PayU response (R-7) or a refund rejected past the retry
     * cap (R-6). Never bumps a segment's refunded_paise (nothing is confirmed-attributed).
     *
     * @param int $orderId
     * @param int $segmentId
     * @param string $reason
     * @param string $why
     * @return void
     */
    private function freezeSegment(int $orderId, int $segmentId, string $reason, string $why): void
    {
        $this->ledger->recordLeg($orderId, $segmentId, 0, 0, null, SplitRefund::STATE_HELD, $reason);
        $this->logger->critical(sprintf(
            'RKD_PayuSplit: refund for segment %d (order %d) FROZEN (HELD) — %s. Auto-processing '
            . 'halted; manual reconciliation required.',
            $segmentId,
            $orderId,
            $why
        ));
    }

    /**
     * @param RefundLeg[] $legs
     * @param string $merchantKey
     * @return RefundLeg|null
     */
    private function findLeg(array $legs, string $merchantKey): ?RefundLeg
    {
        foreach ($legs as $leg) {
            if ($leg->merchantKey === $merchantKey) {
                return $leg;
            }
        }
        return null;
    }
}
