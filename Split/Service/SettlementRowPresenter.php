<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\EligibilityResult;
use RKD\PayuSplit\Split\Model\ItemGate;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Turns raw per-segment settlement data into the display strings the A7 admin grid shows.
 * Pure (no DB, no clock): paise are rendered as exact rupee strings — money stays integer
 * paise everywhere and only the DISPLAY divides by 100 (formatted from the integer, never
 * via float money math); the release-eligibility verdict is surfaced from the SAME
 * {@see SegmentEligibility} the release cron consults, so admin and cron never drift; and a
 * compact refund-leg summary groups the ledger leg states.
 */
class SettlementRowPresenter
{
    /**
     * @param SegmentEligibility $eligibility
     */
    public function __construct(
        private readonly SegmentEligibility $eligibility
    ) {
    }

    /**
     * Format integer paise as an exact rupee string, e.g. 1599 → "₹15.99". Built from the
     * integer (intdiv/modulo) so no float rounding can ever skew a displayed amount.
     *
     * @param int $paise
     * @return string
     */
    public function rupees(int $paise): string
    {
        $negative = $paise < 0;
        $abs = abs($paise);
        $whole = intdiv($abs, 100);
        $fraction = $abs % 100;

        return ($negative ? '-' : '')
            . '₹'
            . number_format($whole)
            . '.'
            . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The release-eligibility verdict for a segment as a human-readable string: "Eligible",
     * or the gate's blocking reason. Delegates to the canonical {@see SegmentEligibility} so
     * the grid shows exactly what the release cron would decide.
     *
     * @param string $segmentState
     * @param ItemGate[] $items
     * @param string[] $holdTypes
     * @param bool $hasBlockingRefund
     * @param string $nowGmt Current time as 'Y-m-d H:i:s' GMT
     * @return string
     */
    public function eligibilityReason(
        string $segmentState,
        array $items,
        array $holdTypes,
        bool $hasBlockingRefund,
        string $nowGmt
    ): string {
        $result = $this->eligibility->evaluate($segmentState, $items, $holdTypes, $hasBlockingRefund, $nowGmt);
        return $this->describe($result);
    }

    /**
     * A human label for the segment owner: the marketplace itself for the commission (parent)
     * segment, or a short seller reference for a vendor segment. (The commission segment is
     * seller 0 — the marketplace — so a bare id would read as blank/"0".)
     *
     * @param string $segmentType One of SplitSegment::TYPE_*
     * @param int $sellerId
     * @return string
     */
    public function vendorLabel(string $segmentType, int $sellerId): string
    {
        return $segmentType === SplitSegment::TYPE_COMMISSION ? 'Marketplace' : '#' . $sellerId;
    }

    /**
     * The release-gate label for the grid. Vendor segments use the canonical item gate
     * verbatim. The commission (parent) segment has no item gates — it releases LAST, after
     * every vendor segment is terminal (INV-14) — so when the item gate would only report
     * "no items to gate" we surface the parent-last rule instead, while still preserving the
     * informative blockers (a non-pre-release state, a hold, a pending refund) the gate gives.
     *
     * @param string $segmentType One of SplitSegment::TYPE_*
     * @param string $segmentState
     * @param ItemGate[] $items
     * @param string[] $holdTypes
     * @param bool $hasBlockingRefund
     * @param string $nowGmt
     * @return string
     */
    public function releaseGate(
        string $segmentType,
        string $segmentState,
        array $items,
        array $holdTypes,
        bool $hasBlockingRefund,
        string $nowGmt
    ): string {
        $gate = $this->eligibilityReason($segmentState, $items, $holdTypes, $hasBlockingRefund, $nowGmt);
        if ($segmentType !== SplitSegment::TYPE_COMMISSION) {
            return $gate;
        }
        // Display-only substitution for the parent segment: the gate's "no items" outcome is
        // not a real blocker for commission, it just means parent-last applies. (Loose match
        // by design — if the canonical reason text ever changes, the worst case is the row
        // shows the raw gate message again, never a money error.)
        return str_contains($gate, 'no items to gate') ? 'Releases last (after all vendors)' : $gate;
    }

    /**
     * Compact summary of a segment's refund-leg states, grouped by count in first-seen
     * order, e.g. ['QUEUED','HELD','QUEUED'] → "QUEUED×2, HELD×1". An em dash when none.
     *
     * @param string[] $legStates The state of each refund leg on the segment
     * @return string
     */
    public function refundSummary(array $legStates): string
    {
        if ($legStates === []) {
            return '—';
        }

        $counts = [];
        foreach ($legStates as $state) {
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $state => $count) {
            $parts[] = $state . '×' . $count;
        }
        return implode(', ', $parts);
    }

    /**
     * @param EligibilityResult $result
     * @return string
     */
    private function describe(EligibilityResult $result): string
    {
        return $result->eligible ? 'Eligible' : (string) $result->reason;
    }
}
