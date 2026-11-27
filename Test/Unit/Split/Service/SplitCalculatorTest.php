<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\SellerShare;
use RKD\PayuSplit\Split\Model\SplitInput;
use RKD\PayuSplit\Split\Service\SplitCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The SplitCalculator turns a marketplace order's per-seller figures into a
 * validated split: each vendor's exact payout + the platform's share as the
 * remainder. Money rules (confirmed 2026-06-08):
 *   - platform = grand_total - sum(vendor payouts)  (absorbs rounding)
 *   - tax follows Webkul's tax_to_seller flag
 *   - affiliate commission is NOT modelled here (paid by platform separately)
 */
class SplitCalculatorTest extends TestCase
{
    private SplitCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new SplitCalculator();
    }

    public function testSingleVendorTakesAllMinusNothingPlatformZero(): void
    {
        // Vendor earns the full grand total, no commission/shipping → 100:0 split (valid per PayU).
        $input = new SplitInput(
            Money::fromRupees('300.00'),
            [new SellerShare(101, Money::fromRupees('300.00'), Money::zero(), false)]
        );

        $result = $this->calc->calculate($input);

        $this->assertSame('300.00', $result->vendorSegments()[101]->toRupeeString());
        $this->assertSame('0.00', $result->platformAmount()->toRupeeString());
        $this->assertTrue($result->total()->equals($input->grandTotal()));
    }

    public function testTwoVendorsPlusPlatformWorkedExample(): void
    {
        // ₹3,690 order: A items 1000 (commission already netted to 900) + tax 180,
        //               B items 2000 (netted to 1800) + tax 360, tax_to_seller=true.
        // Platform keeps commission (300) + shipping (150) = 450 as the remainder.
        $input = new SplitInput(
            Money::fromRupees('3690.00'),
            [
                new SellerShare(101, Money::fromRupees('900.00'), Money::fromRupees('180.00'), true),
                new SellerShare(102, Money::fromRupees('1800.00'), Money::fromRupees('360.00'), true),
            ]
        );

        $result = $this->calc->calculate($input);

        $this->assertSame('1080.00', $result->vendorSegments()[101]->toRupeeString());
        $this->assertSame('2160.00', $result->vendorSegments()[102]->toRupeeString());
        $this->assertSame('450.00', $result->platformAmount()->toRupeeString());
    }

    public function testSumOfAllSegmentsAlwaysEqualsGrandTotal(): void
    {
        $input = new SplitInput(
            Money::fromRupees('3690.00'),
            [
                new SellerShare(101, Money::fromRupees('900.00'), Money::fromRupees('180.00'), true),
                new SellerShare(102, Money::fromRupees('1800.00'), Money::fromRupees('360.00'), true),
            ]
        );

        $result = $this->calc->calculate($input);

        $this->assertTrue($result->total()->equals($input->grandTotal()));
    }

    public function testRoundingDeltaLandsOnPlatform(): void
    {
        // Vendor earns a sub-paise amount (900.125 → rounds to 900.13). Platform absorbs the delta
        // so the 2-decimal segments still sum exactly to the grand total.
        $input = new SplitInput(
            Money::fromRupees('1000.00'),
            [new SellerShare(101, Money::fromRupees('900.1250'), Money::zero(), false)]
        );

        $result = $this->calc->calculate($input);

        $this->assertSame('900.13', $result->vendorSegments()[101]->toRupeeString());
        $this->assertSame('99.87', $result->platformAmount()->toRupeeString());
        $this->assertTrue($result->total()->equals($input->grandTotal()));
    }

    public function testTaxStaysWithPlatformWhenFlagFalse(): void
    {
        // tax_to_seller=false → vendor gets only item earnings, tax stays in platform remainder.
        $input = new SplitInput(
            Money::fromRupees('1180.00'),
            [new SellerShare(101, Money::fromRupees('900.00'), Money::fromRupees('180.00'), false)]
        );

        $result = $this->calc->calculate($input);

        $this->assertSame('900.00', $result->vendorSegments()[101]->toRupeeString());
        $this->assertSame('280.00', $result->platformAmount()->toRupeeString());
    }

    public function testZeroCommissionVendorGetsFullItemAmount(): void
    {
        $input = new SplitInput(
            Money::fromRupees('500.00'),
            [new SellerShare(101, Money::fromRupees('500.00'), Money::zero(), false)]
        );

        $result = $this->calc->calculate($input);

        $this->assertSame('500.00', $result->vendorSegments()[101]->toRupeeString());
        $this->assertSame('0.00', $result->platformAmount()->toRupeeString());
    }

    public function testThrowsWhenVendorSharesExceedGrandTotal(): void
    {
        $this->expectException(SplitCalculationException::class);

        $input = new SplitInput(
            Money::fromRupees('1000.00'),
            [new SellerShare(101, Money::fromRupees('1200.00'), Money::zero(), false)]
        );

        $this->calc->calculate($input);
    }

    public function testThrowsOnEmptySellerList(): void
    {
        $this->expectException(SplitCalculationException::class);

        $this->calc->calculate(new SplitInput(Money::fromRupees('100.00'), []));
    }

    public function testThrowsOnNegativeVendorEarnings(): void
    {
        $this->expectException(SplitCalculationException::class);

        $input = new SplitInput(
            Money::fromRupees('1000.00'),
            [new SellerShare(101, Money::fromPaise(-500), Money::zero(), false)]
        );

        $this->calc->calculate($input);
    }

    public function testThrowsOnZeroGrandTotal(): void
    {
        $this->expectException(SplitCalculationException::class);

        $this->calc->calculate(new SplitInput(
            Money::zero(),
            [new SellerShare(101, Money::zero(), Money::zero(), false)]
        ));
    }

    public function testThrowsOnNonPositiveSellerId(): void
    {
        // seller_id 0 = admin/house products in this marketplace; they have no PayU child
        // merchant, so they must never become a vendor split segment. Fail loud.
        $this->expectException(SplitCalculationException::class);

        $input = new SplitInput(
            Money::fromRupees('500.00'),
            [new SellerShare(0, Money::fromRupees('300.00'), Money::zero(), false)]
        );

        $this->calc->calculate($input);
    }

    public function testZeroPayoutSellerIsExcludedFromSegments(): void
    {
        // A vendor who nets exactly 0 (e.g. 100% coupon) gets NO split segment;
        // their zero contribution simply stays in the platform remainder.
        $input = new SplitInput(
            Money::fromRupees('500.00'),
            [
                new SellerShare(101, Money::fromRupees('300.00'), Money::zero(), false),
                new SellerShare(102, Money::zero(), Money::zero(), false),
            ]
        );

        $result = $this->calc->calculate($input);

        $this->assertArrayNotHasKey(102, $result->vendorSegments());
        $this->assertCount(1, $result->vendorSegments());
        $this->assertSame('200.00', $result->platformAmount()->toRupeeString());
        $this->assertTrue($result->total()->equals($input->grandTotal()));
    }

    public function testResultExposesEverySellerSegment(): void
    {
        $input = new SplitInput(
            Money::fromRupees('900.00'),
            [
                new SellerShare(101, Money::fromRupees('300.00'), Money::zero(), false),
                new SellerShare(102, Money::fromRupees('300.00'), Money::zero(), false),
                new SellerShare(103, Money::fromRupees('200.00'), Money::zero(), false),
            ]
        );

        $result = $this->calc->calculate($input);

        $this->assertCount(3, $result->vendorSegments());
        $this->assertSame('100.00', $result->platformAmount()->toRupeeString());
    }
}
