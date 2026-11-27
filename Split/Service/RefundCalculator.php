<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\RefundCalculationException;
use RKD\PayuSplit\Split\Model\RefundPlan;
use RKD\PayuSplit\Split\Model\Var8Request;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Pure money-math for a customer refund against an already-split transaction. Given a
 * cancelled/returned vendor segment and the customer's prorated PAID amount for the
 * affected items (matrix M-2 — Magento row-level paid, NEVER the list price G_v), it
 * decomposes the refund into the two var8 legs proven by E-12:
 *
 *   amount                = min(paidPaise, segment remaining)   — debits the vendor child
 *   aggregatorRefundAmount = paidPaise − amount                  — debits the parent commission
 *
 * The vendor leg is capped at the segment's unrefunded balance so the cumulative refund
 * on a segment can never exceed its snapshot amount (INV-7); the balance rides on the
 * parent leg, itself bounded by the parent's unrefunded commission. A refund that cannot
 * be funded by segment + parent is corrupt input and halts (fail loud, never over-refund).
 */
class RefundCalculator
{
    /**
     * @param Json $json
     */
    public function __construct(
        private readonly Json $json
    ) {
    }

    /**
     * @param string $childKey PayU child merchant key of the cancelled vendor's segment
     * @param int $segmentAmountPaise Snapshot segment amount (V_v), paise
     * @param int $segmentRefundedPaise Cumulative paise already refunded on this segment
     * @param int $paidPaise Customer's prorated PAID amount for the cancelled items (M-2), paise
     * @param int $parentRemainingPaise Parent segment's unrefunded commission, paise (agg-leg bound)
     * @return RefundPlan
     * @throws RefundCalculationException When inputs are negative or the refund exceeds what can be funded.
     */
    public function calculate(
        string $childKey,
        int $segmentAmountPaise,
        int $segmentRefundedPaise,
        int $paidPaise,
        int $parentRemainingPaise
    ): RefundPlan {
        if ($segmentAmountPaise < 0 || $segmentRefundedPaise < 0 || $paidPaise < 0 || $parentRemainingPaise < 0) {
            throw new RefundCalculationException(
                sprintf('Negative refund input for child "%s" — corrupt settlement data.', $childKey)
            );
        }

        $segmentRemaining = $segmentAmountPaise - $segmentRefundedPaise;
        if ($segmentRemaining < 0) {
            throw new RefundCalculationException(
                sprintf(
                    'Segment for child "%s" is already over-refunded (%d of %d) — halting.',
                    $childKey,
                    $segmentRefundedPaise,
                    $segmentAmountPaise
                )
            );
        }

        // INV-7: the customer can never be refunded more than the vendor segment's
        // unrefunded balance plus the parent commission's. Beyond that there is no escrow
        // to debit — the figures are corrupt; halt rather than post an unfundable leg.
        if ($paidPaise > $segmentRemaining + $parentRemainingPaise) {
            throw new RefundCalculationException(
                sprintf(
                    'Refund %d for child "%s" exceeds fundable balance (segment %d + parent %d).',
                    $paidPaise,
                    $childKey,
                    $segmentRemaining,
                    $parentRemainingPaise
                )
            );
        }

        $amount = min($paidPaise, $segmentRemaining);
        $aggregator = $paidPaise - $amount;

        return new RefundPlan($childKey, $amount, $aggregator);
    }

    /**
     * Assemble one or more RefundPlans into a single PayU var8 request. A single
     * cancel event passes one plan; the O-3 fallback (coalescing a cron tick's pending
     * legs into one call per order) passes several. Each plan becomes a child-keyed
     * entry; var3 is the combined total.
     *
     * @param RefundPlan ...$plans
     * @return Var8Request
     * @throws RefundCalculationException When no plans are given or two plans share a child key.
     */
    public function buildVar8Request(RefundPlan ...$plans): Var8Request
    {
        if ($plans === []) {
            throw new RefundCalculationException('Cannot build a var8 request with no refund legs.');
        }

        $var8 = [];
        $totalPaise = 0;
        foreach ($plans as $plan) {
            if (isset($var8[$plan->childKey])) {
                throw new RefundCalculationException(
                    sprintf('Duplicate child key "%s" in a single var8 request — coalescing bug.', $plan->childKey)
                );
            }
            $var8[$plan->childKey] = $plan->toVar8Entry();
            $totalPaise += $plan->totalPaise();
        }

        return new Var8Request($this->json->serialize($var8), $totalPaise);
    }
}
