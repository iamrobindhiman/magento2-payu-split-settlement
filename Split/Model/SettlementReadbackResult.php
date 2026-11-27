<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The outcome of reading a child segment's settled amount back from PayU
 * (get_aggregator_transactions) during release reconciliation (A5c-5). Immutable.
 *
 * Two distinct outcomes, deliberately NOT collapsed into one nullable amount:
 *  - NO SIGNAL ($hasSettlementSignal = false): PayU exposes no settlement figure for this
 *    (txn, child) yet. This is the normal pre-settlement state (async T+1) AND the ONLY state
 *    reachable in UAT (E-07: sandbox has no settlement→release lifecycle). The recon must leave
 *    the segment RELEASE_REQUESTED and retry — it must NEVER treat "no signal" as released.
 *  - SIGNAL ($hasSettlementSignal = true, $settledPaise = the figure): PayU reports a settled
 *    amount; the recon asserts it equals amount_paise − refunded_paise (INV-18) before RELEASED.
 */
class SettlementReadbackResult
{
    /**
     * @param bool $hasSettlementSignal True only when PayU exposed a settled amount for the pair
     * @param int $settledPaise The settled amount in paise (0 and meaningless when no signal)
     */
    private function __construct(
        public readonly bool $hasSettlementSignal,
        public readonly int $settledPaise
    ) {
    }

    /**
     * No settlement figure was found for this (txn, child) — not settled yet (or UAT). The
     * recon keeps waiting; it never releases on this outcome.
     *
     * @return self
     */
    public static function notFound(): self
    {
        return new self(false, 0);
    }

    /**
     * PayU reported a settled amount for this (txn, child).
     *
     * @param int $settledPaise
     * @return self
     */
    public static function found(int $settledPaise): self
    {
        return new self(true, $settledPaise);
    }
}
