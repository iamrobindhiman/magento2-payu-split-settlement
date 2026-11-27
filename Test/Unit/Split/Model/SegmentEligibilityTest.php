<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\ItemGate;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use PHPUnit\Framework\TestCase;

/**
 * The per-vendor release gate. A segment is releasable only when every item is
 * delivered, past its own window (strictly), free of an open return, the segment
 * is unheld, in a pre-release state, and has no pending refund. Each blocker is
 * asserted independently so a regression points at the exact failing condition.
 */
class SegmentEligibilityTest extends TestCase
{
    private SegmentEligibility $gate;

    protected function setUp(): void
    {
        $this->gate = new SegmentEligibility();
    }

    private function deliveredClosed(): ItemGate
    {
        // Delivered, window closed on 2026-01-01, no return.
        return new ItemGate('2026-01-01 00:00:00', '2026-01-08 00:00:00', SplitSegmentItem::RETURN_NONE);
    }

    public function testEligibleWhenAllItemsDeliveredAndWindowsClosed(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$this->deliveredClosed(), $this->deliveredClosed()],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertTrue($result->eligible);
        $this->assertNull($result->reason);
    }

    public function testBlockedWhenSegmentStateIsTerminal(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_RELEASED,
            [$this->deliveredClosed()],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('not releasable', (string) $result->reason);
    }

    /**
     * Regression pin (A5c money review F-5): a partially- or pending-refunded segment is NEVER
     * releasable. release_settlement has no amount param — it releases the WHOLE held balance —
     * so releasing such a segment would over-pay the vendor. Guards against a future change
     * adding these states to the releasable set.
     */
    public function testRefundStatesAreNeverReleasable(): void
    {
        foreach ([SplitSegment::STATE_PARTIAL_REFUNDED, SplitSegment::STATE_REFUND_PENDING] as $state) {
            $result = $this->gate->evaluate($state, [$this->deliveredClosed()], [], false, '2026-02-01 00:00:00');
            $this->assertFalse($result->eligible, $state . ' must not be releasable');
            $this->assertStringContainsString('not releasable', (string) $result->reason);
        }
    }

    public function testBlockedWhenSegmentHasAHold(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_ELIGIBLE,
            [$this->deliveredClosed()],
            ['RETURN_OPEN'],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('held', (string) $result->reason);
    }

    public function testBlockedWhenARefundIsPending(): void
    {
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$this->deliveredClosed()],
            [],
            true,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('refund', (string) $result->reason);
    }

    public function testBlockedWhenSegmentHasNoItems(): void
    {
        $result = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [], [], false, '2026-02-01 00:00:00');
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('no items', (string) $result->reason);
    }

    public function testBlockedWhenAnItemIsNotDelivered(): void
    {
        $undelivered = new ItemGate(null, null, SplitSegmentItem::RETURN_NONE);
        $result = $this->gate->evaluate(
            SplitSegment::STATE_AWAITING_DELIVERY,
            [$this->deliveredClosed(), $undelivered],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('not yet delivered', (string) $result->reason);
    }

    public function testBlockedWhenAnItemHasAnOpenReturn(): void
    {
        $returning = new ItemGate('2026-01-01 00:00:00', '2026-01-08 00:00:00', SplitSegmentItem::RETURN_OPEN);
        $result = $this->gate->evaluate(
            SplitSegment::STATE_IN_WINDOW,
            [$returning],
            [],
            false,
            '2026-02-01 00:00:00'
        );
        $this->assertFalse($result->eligible);
        $this->assertStringContainsString('open return', (string) $result->reason);
    }

    public function testBlockedWhenWindowStillOpenAndBoundaryIsStrict(): void
    {
        $item = new ItemGate('2026-01-01 00:00:00', '2026-01-08 12:00:00', SplitSegmentItem::RETURN_NONE);

        // now == window close: still open (strict boundary, matrix S-18).
        $atBoundary = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [$item], [], false, '2026-01-08 12:00:00');
        $this->assertFalse($atBoundary->eligible);
        $this->assertStringContainsString('window is still open', (string) $atBoundary->reason);

        // one second before close: still open.
        $before = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [$item], [], false, '2026-01-08 11:59:59');
        $this->assertFalse($before->eligible);

        // one second after close: eligible.
        $after = $this->gate->evaluate(SplitSegment::STATE_IN_WINDOW, [$item], [], false, '2026-01-08 12:00:01');
        $this->assertTrue($after->eligible);
    }
}
