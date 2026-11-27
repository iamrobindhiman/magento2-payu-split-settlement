<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Exception\RefundCalculationException;
use RKD\PayuSplit\Split\Model\RefundPlan;
use RKD\PayuSplit\Split\Service\RefundCalculator;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * The refund calculator is pure money-math: given a cancelled vendor segment and the
 * customer's prorated PAID amount for the cancelled items (matrix M-2 — never list
 * price), it decomposes the refund into the two var8 legs proven by E-12 — the
 * vendor child leg (amount) and the parent-commission leg (aggregatorRefundAmount) —
 * bounded so the cumulative refund never exceeds what the segment + parent can fund
 * (INV-7). All inputs and outputs are integer paise; rupee strings appear only on the
 * var8 wire payload.
 */
class RefundCalculatorTest extends TestCase
{
    private RefundCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RefundCalculator(new Json());
    }

    public function testS05VendorCancelNoDiscountSplitsVendorAndCommissionLegs(): void
    {
        // SEED-1 S-05: vendor C cancels. Customer paid ₹60 for C's item (no discount).
        // C's segment snapshot is ₹50 (5,000p); the ₹10 commission rides on the parent.
        // Expect amount = 5,000 (full vendor segment), aggregatorRefundAmount = 1,000.
        $plan = $this->calculator->calculate(
            'C_KEY',
            5000,   // segment amount (snapshot V_C)
            0,      // nothing refunded yet
            6000,   // prorated PAID for the cancelled item
            12000   // parent remaining
        );

        $this->assertSame('C_KEY', $plan->childKey);
        $this->assertSame(5000, $plan->amountPaise);
        $this->assertSame(1000, $plan->aggregatorPaise);
        $this->assertSame(6000, $plan->totalPaise());
    }

    public function testS36DiscountCancelRefundsProratedPaidNotListPrice(): void
    {
        // S-36 (the M-2 pin): a ₹20 coupon allocates 667p of discount to C's row, so the
        // customer PAID 6,000 − 667 = 5,333p for C's item. C's vendor segment is still
        // 5,000p (the coupon is absorbed by the parent, not the vendor payout). The refund
        // must be 5,333 — NOT the 6,000 list price. amount = min(5,333, 5,000) = 5,000;
        // the 333 balance rides on the parent leg. A calculator that refunded list G_v
        // would post agg = 1,000 and over-refund by 667p (INV-16 violation).
        $plan = $this->calculator->calculate('C_KEY', 5000, 0, 5333, 10000);

        $this->assertSame(5000, $plan->amountPaise);
        $this->assertSame(333, $plan->aggregatorPaise);
        $this->assertSame(5333, $plan->totalPaise());
    }

    public function testDeepDiscountPaidBelowVendorPayoutKeepsBalanceInVendorLeg(): void
    {
        // Pathological deep discount: the customer paid 4,500p for an item whose vendor
        // payout snapshot is 5,000p (marketplace subsidised below the vendor's cut). The
        // customer is refunded only what they paid; amount = min(4,500, 5,000) = 4,500,
        // agg = 0. The 500p residue stays escrowed in the vendor segment (never over-refunded).
        $plan = $this->calculator->calculate('C_KEY', 5000, 0, 4500, 12000);

        $this->assertSame(4500, $plan->amountPaise);
        $this->assertSame(0, $plan->aggregatorPaise);
    }

    public function testSecondRefundRespectsAlreadyRefundedSegmentBalance(): void
    {
        // A prior refund already took 3,000p of this 5,000p segment (remaining 2,000p). A
        // second refund for a 2,500p paid amount caps the vendor leg at the 2,000p balance;
        // the 500p overflow rides on the parent leg (INV-7 cumulative bound enforced via
        // the segment's already-refunded total).
        $plan = $this->calculator->calculate('C_KEY', 5000, 3000, 2500, 12000);

        $this->assertSame(2000, $plan->amountPaise);
        $this->assertSame(500, $plan->aggregatorPaise);
    }

    public function testThrowsWhenRefundExceedsWhatSegmentAndParentCanFund(): void
    {
        // INV-7: the segment remaining (5,000) plus the parent remaining (1,000) can fund
        // only 6,000p. A claimed paid amount of 18,000p is corrupt — halt, never post an
        // unfundable leg that would drain the parent beyond its commission.
        $this->expectException(RefundCalculationException::class);
        $this->calculator->calculate('C_KEY', 5000, 0, 18000, 1000);
    }

    public function testThrowsOnNegativePaidAmount(): void
    {
        $this->expectException(RefundCalculationException::class);
        $this->calculator->calculate('C_KEY', 5000, 0, -1, 12000);
    }

    public function testThrowsWhenSegmentAlreadyOverRefunded(): void
    {
        // refunded > amount should never happen; if the books are corrupt, halt rather
        // than compute against a negative remaining balance.
        $this->expectException(RefundCalculationException::class);
        $this->calculator->calculate('C_KEY', 5000, 6000, 100, 12000);
    }

    public function testBuildsVar8RequestInProvenE12Shape(): void
    {
        // E-12 proved the wire shape: {childKey:{amount, aggregatorRefundAmount}} with
        // 2-decimal rupee strings, and var3 is the total refund in rupees.
        $plan = $this->calculator->calculate('CHLD01', 5000, 0, 6000, 12000);
        $request = $this->calculator->buildVar8Request($plan);

        $decoded = (new Json())->unserialize($request->json());
        $this->assertSame(
            ['amount' => '50.00', 'aggregatorRefundAmount' => '10.00'],
            $decoded['CHLD01']
        );
        $this->assertSame('60.00', $request->totalRupees());
        $this->assertSame(6000, $request->totalPaise());
    }

    public function testBuildsCoalescedVar8RequestForMultipleSegments(): void
    {
        // O-3 fallback: when two segments are refunded in one cron tick, the legs coalesce
        // into a single var8 keyed per child, and var3 is their combined total.
        $a = $this->calculator->calculate('A_KEY', 5000, 0, 6000, 12000);
        $b = $this->calculator->calculate('B_KEY', 5000, 0, 6000, 12000);
        $request = $this->calculator->buildVar8Request($a, $b);

        $decoded = (new Json())->unserialize($request->json());
        $this->assertSame(['amount' => '50.00', 'aggregatorRefundAmount' => '10.00'], $decoded['A_KEY']);
        $this->assertSame(['amount' => '50.00', 'aggregatorRefundAmount' => '10.00'], $decoded['B_KEY']);
        $this->assertSame('120.00', $request->totalRupees());
        $this->assertSame(12000, $request->totalPaise());
    }

    public function testBuildVar8RequestRejectsEmptyPlanSet(): void
    {
        $this->expectException(RefundCalculationException::class);
        $this->calculator->buildVar8Request();
    }

    public function testBuildVar8RequestRejectsDuplicateChildKeys(): void
    {
        // Two legs for the same child in one call would silently overwrite each other in
        // the var8 map — a coalescing bug; refuse it loudly.
        $a = $this->calculator->calculate('A_KEY', 5000, 0, 6000, 12000);
        $b = $this->calculator->calculate('A_KEY', 5000, 0, 6000, 12000);
        $this->expectException(RefundCalculationException::class);
        $this->calculator->buildVar8Request($a, $b);
    }
}
