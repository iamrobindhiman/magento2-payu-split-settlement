<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitRefund;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * The refund-status reconciler (gate item #6) — the ONLY path that advances a refund leg past
 * QUEUED/PENDING. The RefundExecutor writes legs QUEUED/PENDING and bumps refunded_paise
 * optimistically on accept, but nothing polls PayU, so a refund that PayU queues-then-fails would
 * sit QUEUED forever (the customer never refunded, no alarm — M-5). This reconciler polls
 * check_action_status_txnid per pollable leg and resolves it:
 *
 *   SUCCESS    → CAS-promote the leg QUEUED/PENDING → SUCCESS. NO money moves — the refund already
 *                happened at PayU; SUCCESS only confirms it (and, once gate item #6 Part B lands,
 *                stops the leg blocking the segment's remainder release).
 *   FAILURE    → CAS-promote → FAILED, place a FROZEN hold on the segment + CRITICAL alarm. The
 *                customer was NOT refunded and the executor's optimistic refunded_paise bump is now
 *                wrong, so the segment must not silently proceed — a human reconciles (re-fire / fix
 *                the books / clear the hold via the #11 break-glass tool). We never auto-reverse.
 *   PROCESSING → still in flight; leave the leg, retry next tick.
 *   NONE       → no recognizable signal (UAT generic error / unknown status); leave the leg and,
 *                past the alert threshold (by leg age), CRITICAL strand-alarm (never a silent strand).
 *
 * The promotion is a compare-and-set (UPDATE … WHERE state IN (QUEUED,PENDING)) so two overlapping
 * recon ticks can't double-promote or double-freeze. Symmetric to the release ReleaseReconciler.
 * PROD-VERIFY (O-11): check_action_status_txnid returns a generic error in UAT (E-12), so the
 * status reader returns no signal and this whole path is a safe no-op until O-11 calibrates it.
 */
class RefundReconciler
{
    /**
     * @param PayuSplitClient $gateway
     * @param RefundStatusReader $statusReader
     * @param SegmentHoldWriter $holdWriter
     * @param SplitRefundResource $refundResource
     * @param SplitConfig $config
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuSplitClient $gateway,
        private readonly RefundStatusReader $statusReader,
        private readonly SegmentHoldWriter $holdWriter,
        private readonly SplitRefundResource $refundResource,
        private readonly SplitConfig $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param SplitRefund $leg
     * @return void
     */
    public function reconcile(SplitRefund $leg): void
    {
        $legId = (int) $leg->getId();

        // Scope + idempotency: only an in-flight leg is polled. A leg another tick already promoted
        // (SUCCESS/FAILED) — or a HELD/REARMED leg — is left untouched, no second gateway call.
        if (!in_array($leg->getState(), [SplitRefund::STATE_QUEUED, SplitRefund::STATE_PENDING], true)) {
            return;
        }

        $requestId = $leg->getPayuRequestId();
        if ($requestId === null) {
            // No PayU request id (a FULL_UNWIND obligation / absent-parent leg) — not pollable. The
            // loader excludes these; defend in depth so a stray one never hits the gateway.
            return;
        }

        $result = $this->statusReader->read($this->gateway->checkActionStatus($requestId));

        if ($result->isSuccess()) {
            if ($this->promote($leg, SplitRefund::STATE_SUCCESS)) {
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: refund leg %d (order %d, segment %d, request %s) confirmed SUCCESS.',
                    $legId,
                    $leg->getOrderId(),
                    $leg->getSegmentId(),
                    $requestId
                ));
            }
            return;
        }

        if ($result->isFailure()) {
            // The CAS flip to FAILED and the FROZEN hold MUST commit together: a crash between them
            // would leave a FAILED-but-unfrozen leg that nothing ever re-freezes (the loader only
            // re-selects QUEUED/PENDING, and a re-promote no-ops), silently re-opening the M-5
            // strand. One transaction makes it atomic — a crash rolls both back, the leg stays
            // QUEUED/PENDING, and the next tick retries cleanly. Only the tick that wins the CAS
            // freezes + alarms (post-commit), so two ticks can't double-act.
            if ($this->failAndFreeze($leg, $requestId)) {
                $this->logger->critical(sprintf(
                    'RKD_PayuSplit: refund leg %d (order %d, segment %d, request %s) FAILED at PayU after '
                    . 'acceptance — segment FROZEN, customer refund did NOT complete. Manual '
                    . 'reconciliation required.',
                    $legId,
                    $leg->getOrderId(),
                    $leg->getSegmentId(),
                    $requestId
                ));
            }
            return;
        }

        // PROCESSING or NONE: still in flight or no readable signal. Alarm only once the leg has sat
        // past the alert threshold (by its created_at), so a genuinely stuck refund never goes silent.
        if ($this->strandedDays($leg) >= $this->config->getAlertDays()) {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: refund leg %d (order %d, segment %d, request %s) stranded past the alert '
                . 'threshold with no SUCCESS/FAILURE signal — the customer refund is unconfirmed. '
                . 'Investigate.',
                $legId,
                $leg->getOrderId(),
                $leg->getSegmentId(),
                $requestId
            ));
            return;
        }

        $this->logger->info(sprintf(
            'RKD_PayuSplit: refund leg %d (order %d, request %s) still in progress — awaiting confirmation.',
            $legId,
            $leg->getOrderId(),
            $requestId
        ));
    }

    /**
     * Compare-and-set the leg to a resolved state: the conditional UPDATE flips it only if the row
     * is still QUEUED/PENDING, so a concurrent tick that already resolved it affects 0 rows and this
     * call neither re-stamps nor (for a failure) re-freezes/re-alarms.
     *
     * @param SplitRefund $leg
     * @param string $newState SplitRefund::STATE_SUCCESS or STATE_FAILED
     * @return bool True when this call performed the promotion
     */
    private function promote(SplitRefund $leg, string $newState): bool
    {
        $connection = $this->refundResource->getConnection();
        $updated = (int) $connection->update(
            $this->refundResource->getMainTable(),
            ['state' => $newState],
            [
                'refund_id = ?' => (int) $leg->getId(),
                'state IN (?)' => [SplitRefund::STATE_QUEUED, SplitRefund::STATE_PENDING],
            ]
        );
        if ($updated === 0) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: refund leg %d already resolved out of QUEUED/PENDING by a concurrent recon.',
                (int) $leg->getId()
            ));
            return false;
        }
        $leg->setState($newState);
        return true;
    }

    /**
     * Atomically FAIL the leg and FREEZE its segment in ONE transaction (the FAILURE branch's
     * crash-safe analogue of promote()). The conditional UPDATE (QUEUED/PENDING → FAILED) and the
     * FROZEN-hold INSERT commit together, so a crash between them rolls BOTH back — the leg stays
     * QUEUED/PENDING and the next tick retries cleanly, rather than stranding a FAILED-but-unfrozen
     * leg that nothing would re-freeze. Returns true only when this tick won the CAS (1 row) and the
     * freeze committed, so the caller alarms exactly once.
     *
     * @param SplitRefund $leg
     * @param string $requestId
     * @return bool
     */
    private function failAndFreeze(SplitRefund $leg, string $requestId): bool
    {
        $legId = (int) $leg->getId();
        $connection = $this->refundResource->getConnection();
        $connection->beginTransaction();
        try {
            $affected = (int) $connection->update(
                $this->refundResource->getMainTable(),
                ['state' => SplitRefund::STATE_FAILED],
                [
                    'refund_id = ?' => $legId,
                    'state IN (?)' => [SplitRefund::STATE_QUEUED, SplitRefund::STATE_PENDING],
                ]
            );
            if ($affected === 0) {
                // A concurrent tick already resolved this leg — nothing to freeze, no alarm.
                $connection->rollBack();
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: refund leg %d already resolved out of QUEUED/PENDING by a concurrent recon.',
                    $legId
                ));
                return false;
            }
            $this->holdWriter->place(
                $leg->getSegmentId(),
                SplitHold::TYPE_FROZEN,
                sprintf(
                    'Refund leg %d FAILED at PayU after being accepted (request %s) — the customer was '
                    . 'not refunded but refunded_paise was already bumped; frozen for manual '
                    . 'reconciliation (gate item #6).',
                    $legId,
                    $requestId
                )
            );
            $connection->commit();
            $leg->setState(SplitRefund::STATE_FAILED);
            return true;
        } catch (\Throwable $t) {
            // Roll back so the leg stays QUEUED/PENDING and is retried cleanly next tick — never a
            // FAILED leg with no FROZEN marker.
            $connection->rollBack();
            $this->logger->error(
                sprintf(
                    'RKD_PayuSplit: refund leg %d FAILURE handling rolled back (left in flight, will retry) — %s',
                    $legId,
                    $t->getMessage()
                ),
                ['exception' => $t]
            );
            return false;
        }
    }

    /**
     * Whole days the leg has sat in QUEUED/PENDING, by its created_at. Fail-loud on a missing or
     * unparseable timestamp (return the maximum) so a corrupt row alarms LOUDER than an old one,
     * never sits silent (the RBI never-strand-unalarmed rule).
     *
     * @param SplitRefund $leg
     * @return int
     */
    private function strandedDays(SplitRefund $leg): int
    {
        $createdAt = $leg->getData('created_at');
        if ($createdAt === null || $createdAt === '') {
            return PHP_INT_MAX;
        }
        try {
            $created = new \DateTimeImmutable((string) $createdAt);
            $now = new \DateTimeImmutable($this->dateTime->gmtDate());
            return (int) $created->diff($now)->days;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
