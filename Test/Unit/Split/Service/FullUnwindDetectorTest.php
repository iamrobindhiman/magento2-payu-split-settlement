<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\FullUnwindDetector;
use PHPUnit\Framework\TestCase;

/**
 * The full-unwind detector implements the O-9 Option C true-up (LOCKED): a partial
 * cancel/return refunds product price only (marketplace keeps that item's shipping
 * share), but once EVERY vendor segment on an order is terminal the customer is made
 * whole on shipping. The top-up is the customer-paid SHIPPING (snapshotted at split),
 * BOUNDED by the parent's remaining balance — NOT the whole parent remainder. The parent
 * remainder can exceed shipping (un-clawed commission → must not be refunded to the
 * customer) or fall below it (a marketplace-funded discount absorbed into the parent →
 * the unfundable remainder is surfaced as an operating-fund shortfall, never silently
 * lost). The detector is pure and fires only at the LAST terminal event (S-37).
 */
class FullUnwindDetectorTest extends TestCase
{
    private FullUnwindDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FullUnwindDetector();
    }

    public function testAllVendorSegmentsRefundedTopsUpTheShipping(): void
    {
        // SEED-1 S-37 FULL-PARITY: A, B, C all refunded; commission fully clawed, so the
        // parent remainder (₹90) equals the ₹90 shipping the customer paid.
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUNDED],
            9000,
            9000
        );

        $this->assertTrue($result->isFullUnwind);
        $this->assertSame(9000, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }

    public function testMixedCancelledAndRefundedIsFullUnwind(): void
    {
        $result = $this->detector->detect(
            [SplitSegment::STATE_CANCELLED, SplitSegment::STATE_REFUNDED, SplitSegment::STATE_CANCELLED],
            9000,
            9000
        );

        $this->assertTrue($result->isFullUnwind);
        $this->assertSame(9000, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }

    public function testParentHoldingUnclawedCommissionTopsUpOnlyShipping(): void
    {
        // OVER-REFUND GUARD: a vendor cancelled with its commission un-clawed (agg=0 — e.g. a
        // credit-memo'd or deep-discount line where the customer paid <= the vendor payout)
        // leaves that ₹10 commission in the parent, so the parent remainder (₹100) exceeds the
        // ₹90 shipping the customer paid. The top-up must be the shipping (₹90), NOT the whole
        // remainder — refunding the extra ₹10 would hand the customer commission they never paid.
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUNDED],
            10000, // 9000 shipping + 1000 un-clawed commission
            9000
        );

        $this->assertTrue($result->isFullUnwind);
        $this->assertSame(9000, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }

    public function testAbsorbedDiscountCapsTopUpAndFlagsTheShortfall(): void
    {
        // UNDER-REFUND GUARD: a marketplace-funded discount absorbed into the parent leaves the
        // parent remainder (₹25) BELOW the ₹30 shipping the customer paid. The PayU top-up is
        // capped at what the parent actually holds (₹25); the ₹5 the parent cannot fund is
        // surfaced as an operating-fund shortfall — the customer is still owed it, never silently
        // dropped.
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUNDED],
            2500,
            3000
        );

        $this->assertTrue($result->isFullUnwind);
        $this->assertSame(2500, $result->topUpPaise);
        $this->assertSame(500, $result->shortfallPaise);
    }

    public function testOneSurvivingVendorIsNotFullUnwind(): void
    {
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_RELEASED, SplitSegment::STATE_REFUNDED],
            9000,
            9000
        );

        $this->assertFalse($result->isFullUnwind);
        $this->assertSame(0, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }

    public function testInFlightRefundPendingIsNotYetFullUnwind(): void
    {
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUND_PENDING, SplitSegment::STATE_CANCELLED],
            9000,
            9000
        );

        $this->assertFalse($result->isFullUnwind);
        $this->assertSame(0, $result->topUpPaise);
    }

    public function testPartialRefundedCancelCountsAsTerminalInV1(): void
    {
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_PARTIAL_REFUNDED],
            9000,
            9000
        );

        $this->assertTrue($result->isFullUnwind);
        $this->assertSame(9000, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }

    public function testNoVendorSegmentsIsNotFullUnwind(): void
    {
        $result = $this->detector->detect([], 9000, 9000);

        $this->assertFalse($result->isFullUnwind);
        $this->assertSame(0, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }

    public function testCommissionOnlyOrderWithNoShippingEmitsNoTopUp(): void
    {
        // Commission-only order, no shipping paid (free shipping) and parent drained — full
        // unwind is true but there is nothing to top up and no shortfall.
        $result = $this->detector->detect(
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_REFUNDED],
            0,
            0
        );

        $this->assertTrue($result->isFullUnwind);
        $this->assertSame(0, $result->topUpPaise);
        $this->assertSame(0, $result->shortfallPaise);
    }
}
