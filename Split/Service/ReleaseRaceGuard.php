<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;

/**
 * The single source of truth for the release ineligibility re-check: the time-flippable signals
 * that can turn an already-decided-releasable segment ineligible between the eligibility read and
 * the actual disbursement — a hold placed at cancel/return time, a refund leg opened by a
 * concurrent tick (INV-19), a return opened on an item, an out-of-band Magento credit memo, or the
 * seller portion cancelled after the gate (vendor only).
 *
 * BOTH release disbursement sites share this one implementation:
 *  - {@see ReleaseProcessor} re-checks it AFTER the CAS claim and BEFORE firing (rolling the claim
 *    back if it trips — the scan→claim TOCTOU guard);
 *  - {@see ReleaseReconciler::handleNoSignal} re-checks it before an overdue transport-fail
 *    RE-fire (freezing if it trips).
 *
 * Keeping ONE implementation is the fix for gate item F2: the two sites had drifted — the recon
 * re-fire re-checked nothing but the FROZEN hold, so a cancel/return/refund landing during a
 * release strand was steamrolled into a disbursement to a no-longer-eligible vendor.
 *
 * Delivery and return windows cannot regress and a terminal sibling cannot un-terminalise, so they
 * need no re-check. Reads external signals only — never the segment's own state (the caller has
 * already moved it to RELEASE_REQUESTED via its claim).
 */
class ReleaseRaceGuard
{
    /**
     * @param SegmentHoldLoader $holdLoader
     * @param RefundLedger $refundLedger
     * @param SegmentItemLoader $itemLoader
     * @param CreditMemoRefundReader $creditMemoReader
     * @param SellerOrderStatus $sellerOrderStatus
     */
    public function __construct(
        private readonly SegmentHoldLoader $holdLoader,
        private readonly RefundLedger $refundLedger,
        private readonly SegmentItemLoader $itemLoader,
        private readonly CreditMemoRefundReader $creditMemoReader,
        private readonly SellerOrderStatus $sellerOrderStatus
    ) {
    }

    /**
     * @param SplitSegment $segment a segment already decided releasable (claimed / RELEASE_REQUESTED)
     * @return bool true if a race made the segment ineligible after it was decided releasable
     */
    public function racedIntoIneligibility(SplitSegment $segment): bool
    {
        $segmentId = (int) $segment->getId();

        if ($this->holdLoader->holdTypes($segmentId) !== []) {
            return true;
        }
        // #12: a release-BLOCKING refund leg (PENDING/QUEUED/HELD) racing in aborts the claim; a
        // COMPLETED (SUCCESS) refund does NOT — it's the normal state of a releasable remainder, so
        // using activeLegs here (which includes SUCCESS) would abort every PARTIAL_REFUNDED release
        // and loop the claim/rollback forever.
        if ($this->refundLedger->releaseBlockingLegs($segmentId) !== []) {
            return true;
        }
        // A return opened in the gate→claim (or strand) window flips a per-item return_state to
        // RETURN_OPEN (MarkReturnOpenOnReturnStatus). Parent commission has no items → no-op loop.
        $orderItemIds = [];
        foreach ($this->itemLoader->loadItems($segmentId) as $item) {
            if ($item->getReturnState() === SplitSegmentItem::RETURN_OPEN) {
                return true;
            }
            $orderItemId = $item->getOrderItemId();
            if ($orderItemId > 0) {
                $orderItemIds[] = $orderItemId;
            }
        }
        // An out-of-band Magento credit memo the readback recon cannot catch (PayU still holds the
        // full amount). Empty item set (parent commission) reads 0 — vendor-only by construction.
        if ($this->creditMemoReader->refundedPaiseForItems($orderItemIds)
            + $this->creditMemoReader->orderLevelRefundPaise((int) $segment->getOrderId()) > 0) {
            return true;
        }
        if ($segment->getSegmentType() === SplitSegment::TYPE_VENDOR
            && $this->sellerOrderStatus->isSellerCanceled(
                (int) $segment->getOrderId(),
                (int) $segment->getSellerId()
            )
        ) {
            return true;
        }
        return false;
    }
}
