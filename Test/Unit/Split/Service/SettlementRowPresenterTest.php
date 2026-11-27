<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ItemGate;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Service\SettlementRowPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The presenter turns raw per-segment settlement data into the display strings the A7
 * admin grid shows: paise rendered as exact rupee strings (money is integer paise
 * everywhere; only display divides by 100), the release-eligibility verdict surfaced from
 * the SAME pure SegmentEligibility the cron uses (so admin and cron never drift), and a
 * compact refund-leg summary. Pure — no DB, no clock.
 */
class SettlementRowPresenterTest extends TestCase
{
    private SettlementRowPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new SettlementRowPresenter(new SegmentEligibility());
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function paiseProvider(): array
    {
        return [
            'sub-rupee'      => [1599, '₹15.99'],
            'whole rupees'   => [5000, '₹50.00'],
            'zero'           => [0, '₹0.00'],
            'single paisa'   => [1, '₹0.01'],
            'thousands group'=> [10000000, '₹100,000.00'],
            'negative'       => [-2500, '-₹25.00'],
        ];
    }

    /**
     * @dataProvider paiseProvider
     * @param int $paise
     * @param string $expected
     */
    public function testRupeesFormatsPaiseExactly(int $paise, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->rupees($paise));
    }

    public function testEligibilityReasonIsEligibleWhenTheGatePasses(): void
    {
        $items = [new ItemGate('2026-06-01 00:00:00', '2026-06-05 00:00:00', SplitSegmentItem::RETURN_NONE)];
        $reason = $this->presenter->eligibilityReason(
            SplitSegment::STATE_ELIGIBLE,
            $items,
            [],
            false,
            '2026-06-10 00:00:00'
        );
        $this->assertSame('Eligible', $reason);
    }

    public function testEligibilityReasonSurfacesTheBlockingReasonFromTheGate(): void
    {
        // An item not yet delivered is blocked — the presenter shows the SAME reason string
        // the release cron would compute (single source of truth).
        $items = [new ItemGate(null, null, SplitSegmentItem::RETURN_NONE)];
        $reason = $this->presenter->eligibilityReason(
            SplitSegment::STATE_IN_WINDOW,
            $items,
            [],
            false,
            '2026-06-10 00:00:00'
        );
        $this->assertSame('an item is not yet delivered', $reason);
    }

    public function testVendorLabelIsMarketplaceForCommissionSegments(): void
    {
        $this->assertSame('Marketplace', $this->presenter->vendorLabel(SplitSegment::TYPE_COMMISSION, 0));
    }

    public function testVendorLabelIsTheSellerReferenceForVendorSegments(): void
    {
        $this->assertSame('#18', $this->presenter->vendorLabel(SplitSegment::TYPE_VENDOR, 18));
    }

    public function testReleaseGateForVendorDelegatesToTheEligibilityGate(): void
    {
        // A vendor segment uses the canonical item gate verbatim.
        $items = [new ItemGate(null, null, SplitSegmentItem::RETURN_NONE)];
        $this->assertSame(
            'an item is not yet delivered',
            $this->presenter->releaseGate(SplitSegment::TYPE_VENDOR, SplitSegment::STATE_IN_WINDOW, $items, [], false, '2026-06-10 00:00:00')
        );
    }

    public function testReleaseGateForCommissionInAReleasableStateShowsParentLast(): void
    {
        // A commission (parent) segment has no item gates; in a pre-release state it is simply
        // waiting on the parent-last rule, not on "no items". Surface that, not the raw gate.
        $this->assertSame(
            'Releases last (after all vendors)',
            $this->presenter->releaseGate(SplitSegment::TYPE_COMMISSION, SplitSegment::STATE_PENDING, [], [], false, '2026-06-10 00:00:00')
        );
    }

    public function testReleaseGateForCommissionPreservesInformativeStateMessages(): void
    {
        // A commission segment with an in-flight refund (REFUND_PENDING — still non-releasable after
        // #12, unlike PARTIAL_REFUNDED which can now release its remainder) must still show WHY it is
        // not releasable (its refund state), not the generic parent-last line.
        $this->assertSame(
            'segment state REFUND_PENDING is not releasable',
            $this->presenter->releaseGate(SplitSegment::TYPE_COMMISSION, SplitSegment::STATE_REFUND_PENDING, [], [], false, '2026-06-10 00:00:00')
        );
    }

    public function testRefundSummaryIsADashWhenNoLegs(): void
    {
        $this->assertSame('—', $this->presenter->refundSummary([]));
    }

    public function testRefundSummaryGroupsLegStatesByCountInFirstSeenOrder(): void
    {
        $this->assertSame('QUEUED×1', $this->presenter->refundSummary(['QUEUED']));
        $this->assertSame(
            'QUEUED×2, HELD×1',
            $this->presenter->refundSummary(['QUEUED', 'HELD', 'QUEUED'])
        );
    }
}
