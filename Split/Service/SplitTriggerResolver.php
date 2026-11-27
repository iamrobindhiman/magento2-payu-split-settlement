<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\TriggerResult;

/**
 * Decides whether — and why — an order's split should fire now (settlement matrix
 * §1.2 / §5). The trigger is the EARLIEST of three conditions, evaluated in order:
 *
 *   1. ALL_TERMINAL — every vendor sub-order is shipped-or-cancelled (the normal
 *      path: we now know the final delivery picture, so we can split).
 *   2. MONEY_EVENT — a money movement (e.g. a customer-cancel refund) needs the
 *      split to exist before it can be applied (matrix: a var8 refund leg cannot
 *      run on an un-split txn).
 *   3. CEILING — the T+N-day hard ceiling (matrix M-1). A compliance control, not
 *      a fallback: if PayU auto-settles an un-split aggregator txn the marketplace
 *      holds customer funds without a PA licence (the RBI launch-gate violation).
 *
 * Pure: every input — the vendor seller-ids, their shipped-or-cancelled flags, the
 * money-event flag, paid-at / now timestamps, and the day thresholds — is passed
 * in. No DB, no clock. The split-trigger scan (A5a) sources the facts and persists
 * the decision; this class only decides.
 */
class SplitTriggerResolver
{
    public const TRIGGER_ALL_TERMINAL = 'ALL_TERMINAL';
    public const TRIGGER_MONEY_EVENT = 'MONEY_EVENT';
    public const TRIGGER_CEILING = 'CEILING';

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param int[] $vendorSellerIds The vendor seller-ids carried by the order's split (commission excluded)
     * @param array<int, bool> $terminalFlags seller_id => shipped-or-cancelled; a missing id reads as not terminal
     * @param bool $moneyEvent Whether a money event needing the split is pending
     * @param string|null $paidAtGmt Payment-capture timestamp ('Y-m-d H:i:s' GMT), or null if unknown
     * @param string $nowGmt Current time ('Y-m-d H:i:s' GMT)
     * @param int $ceilingDays Days after paid-at at which the hard ceiling fires
     * @param int $alertDays Days after paid-at at which a compliance alert is raised
     * @return TriggerResult
     */
    public function resolve(
        array $vendorSellerIds,
        array $terminalFlags,
        bool $moneyEvent,
        ?string $paidAtGmt,
        string $nowGmt,
        int $ceilingDays,
        int $alertDays
    ): TriggerResult {
        $ageDays = $this->ageInDays($paidAtGmt, $nowGmt);

        // alertDue is a function of AGE ALONE, independent of whether a trigger fires.
        // The scan alarms on any aged order that does not actually reach a completed
        // split — including a trigger-ready-but-unsplittable one (no mihpayid, perpetual
        // transient) which would otherwise sail past the ceiling silently (matrix M-1).
        $alertDue = $ageDays >= $alertDays;

        if ($this->allTerminal($vendorSellerIds, $terminalFlags)) {
            return new TriggerResult(self::TRIGGER_ALL_TERMINAL, $alertDue);
        }
        if ($moneyEvent) {
            return new TriggerResult(self::TRIGGER_MONEY_EVENT, $alertDue);
        }
        if ($ageDays >= $ceilingDays) {
            return new TriggerResult(self::TRIGGER_CEILING, $alertDue);
        }

        return new TriggerResult(null, $alertDue);
    }

    /**
     * Every vendor segment must be present in the flags map AND terminal. An empty
     * vendor set never qualifies (a commission-only order is not "all terminal").
     *
     * @param int[] $vendorSellerIds
     * @param array<int, bool> $terminalFlags
     * @return bool
     */
    private function allTerminal(array $vendorSellerIds, array $terminalFlags): bool
    {
        if ($vendorSellerIds === []) {
            return false;
        }
        foreach ($vendorSellerIds as $sellerId) {
            if (($terminalFlags[$sellerId] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whole days elapsed between paid-at and now. A null/unparseable paid-at yields
     * 0 (the ceiling can never be reached without a known payment time).
     *
     * @param string|null $paidAtGmt
     * @param string $nowGmt
     * @return int
     */
    private function ageInDays(?string $paidAtGmt, string $nowGmt): int
    {
        if ($paidAtGmt === null || $paidAtGmt === '') {
            return 0;
        }
        $utc = new \DateTimeZone('UTC');
        try {
            $paid = new \DateTimeImmutable($paidAtGmt, $utc);
            $now = new \DateTimeImmutable($nowGmt, $utc);
        } catch (\Exception) {
            // A malformed timestamp must not crash the scan (which would terminalise the
            // header). Degrade to age 0 — no ceiling, no alert.
            return 0;
        }
        $delta = $now->getTimestamp() - $paid->getTimestamp();
        if ($delta <= 0) {
            return 0;
        }
        return intdiv($delta, self::SECONDS_PER_DAY);
    }
}
