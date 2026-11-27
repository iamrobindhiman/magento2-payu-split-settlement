<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * A5c-4b — the INV-17 predicate. Answers one question: "is PayU managing this vendor's settlement
 * for this order?" When true, Webkul's native admin "Pay Seller" must be suppressed so the vendor
 * is never paid twice (once by the PayU release, once by a manual Webkul payout).
 *
 * The rule has two gates. The HEADER must be in the ACTIVE split lifecycle — PENDING, ELIGIBLE,
 * REQUESTED or DONE — i.e. a split that has been recorded and is heading for, or has reached,
 * settlement. PayU owns (or is about to own) the vendor money across ALL of these, not just at DONE:
 * the REQUESTED window in particular (split POSTed to PayU, our header awaiting cron reconciliation
 * of a possibly-lost response) is a real double-pay window if a manual payout slips through before
 * DONE. The terminal "PayU will never settle this" header states — NOT_REQUIRED, FAILED, SKIPPED —
 * are deliberately NOT managed: the marketplace owes the vendor through Webkul's normal payout, so
 * blocking those would strand them.
 *
 * The SEGMENT gate is broad: any vendor-type segment for the seller across its whole lifecycle —
 * held, in-flight, RELEASED, REFUND_PENDING/PARTIAL_REFUNDED/REFUNDED/CANCELLED — blocks, the ONLY
 * carve-out being NOT_IN_SPLIT. (WRITTEN_OFF is intentionally still treated as managed — a
 * conservative default; the manual payout is surfaced for a human decision rather than silently
 * allowing a possible double-pay.)
 *
 * NOTE for future maintainers: vendor->submerchant mapping is all-or-nothing per order, so a partly
 * unmapped order becomes a SKIPPED header with ZERO segments (never blocked) rather than a DONE
 * split carrying a NOT_IN_SPLIT segment. As of this writing NOT_IN_SPLIT, WRITTEN_OFF and CANCELLED
 * are never assigned to a segment anywhere in the module — the carve-out and the WRITTEN_OFF
 * conservative-block are defensive. When a write-off writer is introduced it MUST re-decide the
 * payout semantics here: a "PayU settled, books written off" segment should stay blocked, but a
 * "PayU never settled, gave up" segment must be carved out, else that vendor is permanently stranded.
 *
 * This is a pure predicate — it never logs and never decides policy on error. The plugins own the
 * fail-closed behaviour (a thrown read error there blocks the payout), so this stays trivially
 * testable as truth.
 *
 * It is self-gating, NOT tied to the release_enabled kill-switch: the double-pay risk exists the
 * moment an active split exists (PayU is holding, or about to hold, the funds), independent of
 * whether the release executor is switched on. Where no split data exists (e.g. production before
 * split goes live) it returns false for every order, so wiring it now is a no-op until split is live.
 */
class PayuReleaseGuard
{
    /**
     * Header split_state values for which PayU owns (or is about to own) the vendor's money — a
     * manual Webkul payout must be suppressed. Excludes the terminal NOT_REQUIRED/FAILED/SKIPPED
     * states, where the marketplace settles the vendor through Webkul as normal.
     */
    private const ACTIVE_SPLIT_STATES = [
        SplitOrder::STATE_PENDING,
        SplitOrder::STATE_ELIGIBLE,
        SplitOrder::STATE_REQUESTED,
        SplitOrder::STATE_DONE,
    ];

    /**
     * @param SplitOrderRepositoryInterface $repository
     * @param SegmentLoader $segmentLoader
     */
    public function __construct(
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly SegmentLoader $segmentLoader
    ) {
    }

    /**
     * @param int $orderId Magento order entity id
     * @param int $sellerId Webkul seller id
     * @return bool True when PayU owns this vendor's settlement (Webkul payout must be suppressed)
     */
    public function isManagedByPayu(int $orderId, int $sellerId): bool
    {
        $header = $this->repository->getByOrderId($orderId);
        if ($header === null || !in_array($header->getSplitState(), self::ACTIVE_SPLIT_STATES, true)) {
            return false;
        }

        foreach ($this->segmentLoader->loadByOrderId($orderId) as $segment) {
            if ($segment->getSellerId() !== $sellerId) {
                continue;
            }
            if ($segment->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
                continue;
            }
            if ($segment->getState() === SplitSegment::STATE_NOT_IN_SPLIT) {
                continue;
            }
            return true;
        }

        return false;
    }
}
