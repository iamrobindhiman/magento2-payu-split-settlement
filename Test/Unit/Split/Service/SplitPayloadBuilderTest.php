<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\SplitResult;
use RKD\PayuSplit\Split\Service\SplitPayloadBuilder;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * The payload builder turns an OrderSplitPlan into PayU's payment_split var1 JSON.
 * Correctness here is money-correctness: the per-segment amounts must be two-decimal
 * rupee strings, keyed by the right child / parent merchant key, and sum to the
 * grand total (else PayU rejects with AGG-108).
 */
class SplitPayloadBuilderTest extends TestCase
{
    private Json $json;
    private SplitPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->json = new Json();
        $this->builder = new SplitPayloadBuilder($this->json);
    }

    public function testBuildsAbsoluteSplitWithVendorAndPlatformSegments(): void
    {
        // Order 000000045 paid ₹799.20: vendor 12 earns ₹679.32, platform keeps ₹119.88 (E-05 shape).
        $result = new SplitResult(
            [12 => Money::fromPaise(67932)],
            Money::fromPaise(11988),
            Money::fromPaise(79920)
        );
        $plan = new OrderSplitPlan(45, '000000045', $result, [12 => 'CHLD01']);

        $decoded = $this->json->unserialize(
            $this->builder->build($plan, '100000000000000002', 'PRNT01')
        );

        $this->assertSame('absolute', $decoded['type']);
        $this->assertSame('100000000000000002', $decoded['payuId']);

        // Vendor segment keyed by its PayU child merchant key.
        $this->assertSame('679.32', $decoded['splitInfo']['CHLD01']['aggregatorSubAmt']);
        $this->assertSame('000000045-12', $decoded['splitInfo']['CHLD01']['aggregatorSubTxnId']);

        // Platform segment keyed by the parent (aggregator) key → PayU labels it "commission".
        $this->assertSame('119.88', $decoded['splitInfo']['PRNT01']['aggregatorSubAmt']);
        $this->assertSame('000000045-PLATFORM', $decoded['splitInfo']['PRNT01']['aggregatorSubTxnId']);
    }

    public function testSegmentAmountsSumToTheGrandTotal(): void
    {
        // 500.00 + 200.00 (vendors) + 99.20 (platform) = 799.20 = what the customer paid.
        $result = new SplitResult(
            [12 => Money::fromPaise(50000), 34 => Money::fromPaise(20000)],
            Money::fromPaise(9920),
            Money::fromPaise(79920)
        );
        $plan = new OrderSplitPlan(45, '000000045', $result, [12 => 'AAA', 34 => 'BBB']);

        $decoded = $this->json->unserialize($this->builder->build($plan, 'PID', 'PARENT'));

        $sum = 0.0;
        foreach ($decoded['splitInfo'] as $segment) {
            $sum += (float)$segment['aggregatorSubAmt'];
        }
        $this->assertEqualsWithDelta(799.20, $sum, 0.0001);
    }

    public function testZeroPlatformShareOmitsTheParentSegment(): void
    {
        // 100% to one vendor — no "0.00" platform instruction to PayU.
        $result = new SplitResult(
            [12 => Money::fromPaise(79920)],
            Money::zero(),
            Money::fromPaise(79920)
        );
        $plan = new OrderSplitPlan(45, '000000045', $result, [12 => 'CHLD01']);

        $decoded = $this->json->unserialize($this->builder->build($plan, 'PID', 'PRNT01'));

        $this->assertArrayHasKey('CHLD01', $decoded['splitInfo']);
        $this->assertArrayNotHasKey('PRNT01', $decoded['splitInfo']);
        $this->assertCount(1, $decoded['splitInfo']);
    }

    public function testAmountsAreAlwaysTwoDecimals(): void
    {
        $result = new SplitResult(
            [12 => Money::fromPaise(50000)],
            Money::fromPaise(5),
            Money::fromPaise(50005)
        );
        $plan = new OrderSplitPlan(7, '000000007', $result, [12 => 'CHILD']);

        $decoded = $this->json->unserialize($this->builder->build($plan, 'PID', 'PARENT'));

        $this->assertSame('500.00', $decoded['splitInfo']['CHILD']['aggregatorSubAmt']);
        $this->assertSame('0.05', $decoded['splitInfo']['PARENT']['aggregatorSubAmt']);
    }

    public function testThrowsWhenAVendorSegmentHasNoChildKey(): void
    {
        // Defensive: A2 resolves every key before producing the plan, but never split on a gap.
        $result = new SplitResult(
            [99 => Money::fromPaise(50000)],
            Money::fromPaise(29920),
            Money::fromPaise(79920)
        );
        $plan = new OrderSplitPlan(45, '000000045', $result, []);

        $this->expectException(SplitCalculationException::class);
        $this->builder->build($plan, 'PID', 'PARENT');
    }

    public function testZeroAmountVendorSegmentIsOmitted(): void
    {
        // Defense-in-depth: a zero vendor payout produces no "0.00" segment; the sum still holds.
        $result = new SplitResult(
            [12 => Money::fromPaise(79920), 34 => Money::zero()],
            Money::zero(),
            Money::fromPaise(79920)
        );
        $plan = new OrderSplitPlan(45, '000000045', $result, [12 => 'CHLD01', 34 => 'unused']);

        $decoded = $this->json->unserialize($this->builder->build($plan, 'PID', 'PARENT'));

        $this->assertArrayHasKey('CHLD01', $decoded['splitInfo']);
        $this->assertArrayNotHasKey('unused', $decoded['splitInfo']);
        $this->assertCount(1, $decoded['splitInfo']);
    }
}
