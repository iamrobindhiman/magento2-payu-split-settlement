<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\SplitHold;
use Magento\Framework\Exception\LocalizedException;

/**
 * Break-glass clear of a FROZEN hold (gate item #11) — the recovery half of the frozen-hold
 * lifecycle (place → alarm → clear). A TYPE_FROZEN hold halts a segment's auto-processing (release
 * readback mismatch INV-18, the O-13 parent child-key gap, the out-of-band credit-memo freeze on
 * the release #5 / refund F5 paths, or the refund reconciler's post-FAILURE freeze F6) and, via
 * parent-last (INV-14), blocks the parent commission until a human reconciles. This clears the hold
 * so the segment re-enters the normal release/refund scans.
 *
 * KEY SAFETY PROPERTY: clearing a hold moves NO money. It only re-exposes the segment to the gates,
 * which RE-CHECK every condition (the same credit-memo signal in ReleaseProcessor::isReleasable and
 * RefundExecutor, the INV-18 readback, cancel/return/refund-leg gates) and RE-FREEZE if the
 * condition still holds. The downstream gates are the real backstop; this early re-check just
 * refuses a clear that would obviously churn (avoiding a place→clear→re-freeze loop).
 *
 * It re-checks the OUT-OF-BAND CREDIT-MEMO condition (the auto-checkable freeze cause) and REFUSES
 * if any credit memo — per-item or order-level — still stands: the operator must reverse/reconcile
 * it first. It also refuses when the segment is missing or carries no FROZEN hold.
 *
 * SCOPE — refund-ledger freezes are OUT of scope (the F6 hole). The refund reconciler's post-FAILURE
 * freeze ({@see RefundReconciler::failAndFreeze}) places a FROZEN hold on a segment whose
 * refunded_paise was ALREADY optimistically bumped before PayU failed the refund — so the balance is
 * WRONG, and NO downstream gate corrects it (the "re-check and re-freeze" backstop covers the
 * credit-memo / readback / O-13 causes, NOT a corrupted ledger). If such a hold were cleared, the
 * FAILED leg no longer blocks RefundableSegments and the UNGATED refund executor would re-fire a
 * var8 on the corrupted baseline → silent under-refund. So this REFUSES any segment with refund-
 * ledger activity (a failed or active leg): the operator must reconcile the refund first — this tool
 * only clears freezes whose cause is OUTSIDE the refund ledger. Note: a pure F6 segment (FAILED leg,
 * no HELD leg) is NOT re-armable via RefundRearmer either, and no tool corrects the inflated
 * refunded_paise — its recovery is manual-DB-only (kept visible by the #11 StaleFrozenSegments alarm).
 *
 * It is flag-gated OFF at the controller and audited there; this service is the pure decision + mutation.
 */
class FrozenHoldClearer
{
    /**
     * @param SegmentLoader $segmentLoader
     * @param SegmentHoldLoader $holdLoader
     * @param SegmentHoldWriter $holdWriter
     * @param CreditMemoRefundReader $creditMemoReader
     * @param SegmentItemLoader $itemLoader
     * @param RefundLedger $refundLedger
     */
    public function __construct(
        private readonly SegmentLoader $segmentLoader,
        private readonly SegmentHoldLoader $holdLoader,
        private readonly SegmentHoldWriter $holdWriter,
        private readonly CreditMemoRefundReader $creditMemoReader,
        private readonly SegmentItemLoader $itemLoader,
        private readonly RefundLedger $refundLedger
    ) {
    }

    /**
     * Clear the FROZEN hold on a segment after re-verifying its out-of-band condition is gone.
     *
     * @param int $segmentId
     * @return int Number of FROZEN hold rows removed (always >= 1 on success)
     * @throws LocalizedException When the segment is missing, carries no FROZEN hold, or its
     *         out-of-band credit-memo condition still holds.
     */
    public function clear(int $segmentId): int
    {
        $segment = $this->segmentLoader->loadById($segmentId);
        if ($segment === null) {
            throw new LocalizedException(__('Segment %1 was not found.', $segmentId));
        }

        if (!in_array(SplitHold::TYPE_FROZEN, $this->holdLoader->holdTypes($segmentId), true)) {
            throw new LocalizedException(__('Segment %1 has no FROZEN hold to clear.', $segmentId));
        }

        // F6 SAFETY (do FIRST): refuse if the segment has ANY refund-ledger entanglement — a FAILED
        // leg (the refund reconciler's post-FAILURE freeze signature: refunded_paise optimistically
        // bumped, then the refund FAILED at PayU, so the balance is now WRONG) or an active
        // PENDING/QUEUED/SUCCESS/HELD leg. Clearing the FROZEN hold here would drop the segment back
        // onto the UNGATED refund executor (a FAILED leg no longer blocks RefundableSegments), which
        // would re-fire a var8 on the corrupted balance — a silent under-refund that no downstream
        // gate catches. Reconcile the refund first (Re-arm Refund / manual); this tool only clears
        // freezes whose cause is OUTSIDE the refund ledger (credit-memo / readback / O-13, which
        // carry no refund leg).
        if ($this->refundLedger->activeLegs($segmentId) !== []
            || $this->refundLedger->failedLegCount($segmentId) > 0
        ) {
            throw new LocalizedException(__(
                'Segment %1 has an unresolved refund leg (failed, held, or in-flight). Clearing the '
                . 'FROZEN hold could re-fire a refund on a corrupted balance. Reconcile the refund '
                . 'ledger first — re-arm a HELD refund via "Re-arm Refund"; a FAILED refund needs a '
                . 'manual balance correction (refunded_paise is not auto-corrected) — then clear the hold.',
                $segmentId
            ));
        }

        // Re-check the out-of-band credit-memo condition against the segment's OWN authoritative
        // order id (loaded, never caller-supplied). Per-item (vendor-attributable) PLUS order-level
        // (adjustment/shipping). If any credit memo still stands, refuse — clearing now would just
        // be re-frozen by ReleaseProcessor / RefundExecutor on the next scan.
        $orderId = $segment->getOrderId();
        $creditMemoPaise = $this->creditMemoReader->refundedPaiseForItems($this->itemLoader->loadOrderItemIds($segmentId))
            + $this->creditMemoReader->orderLevelRefundPaise($orderId);
        if ($creditMemoPaise > 0) {
            throw new LocalizedException(__(
                'Segment %1 still has an unreconciled out-of-band Magento credit memo (%2 paise). '
                . 'Reverse or reconcile it before clearing the freeze — otherwise the segment would '
                . 'simply be re-frozen on the next scan.',
                $segmentId,
                $creditMemoPaise
            ));
        }

        return $this->holdWriter->remove($segmentId, SplitHold::TYPE_FROZEN);
    }
}
