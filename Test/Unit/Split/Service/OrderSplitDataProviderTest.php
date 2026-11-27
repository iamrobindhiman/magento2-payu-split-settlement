<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Exception\SubmerchantNotMappedException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderItemShare;
use RKD\PayuSplit\Split\Model\SellerShare;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\OrderItemEarningsReader;
use RKD\PayuSplit\Split\Service\OrderSplitDataProvider;
use RKD\PayuSplit\Split\Service\SellerEarningsReader;
use RKD\PayuSplit\Split\Service\SplitCalculator;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The data provider turns a paid order into a validated OrderSplitPlan:
 * grand total + per-seller earnings -> SplitCalculator -> SplitResult, then a
 * PayU child key resolved for every vendor that is actually in the split.
 * Money rules: platform = remainder (absorbs shipping + commission); tax follows
 * the tax_to_seller flag; an unmapped vendor aborts the whole split (fail-loud).
 */
class OrderSplitDataProviderTest extends TestCase
{
    private function orderMock(float $grandTotal, string $incrementId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getIncrementId')->willReturn($incrementId);
        return $order;
    }

    private function activeSubmerchant(string $key): Submerchant
    {
        $submerchant = $this->createMock(Submerchant::class);
        $submerchant->method('isActive')->willReturn(true);
        $submerchant->method('getMerchantKey')->willReturn($key);
        return $submerchant;
    }

    /**
     * @param OrderInterface $order
     * @param SellerShare[] $shares
     * @param SubmerchantRepositoryInterface $repo
     * @param OrderItemShare[] $items Per-item shares the item reader returns (default none)
     * @return OrderSplitDataProvider
     */
    private function provider(
        OrderInterface $order,
        array $shares,
        SubmerchantRepositoryInterface $repo,
        array $items = []
    ): OrderSplitDataProvider {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

        $reader = $this->createMock(SellerEarningsReader::class);
        $reader->method('read')->willReturn($shares);

        $itemReader = $this->createMock(OrderItemEarningsReader::class);
        $itemReader->method('read')->willReturn($items);

        return new OrderSplitDataProvider($orderRepository, $reader, $repo, new SplitCalculator(), $itemReader);
    }

    public function testBuildsMultiVendorSplitWithResolvedKeys(): void
    {
        $shares = [
            new SellerShare(11, Money::fromRupees('600.00'), Money::fromRupees('50.00'), true),  // payout 650
            new SellerShare(12, Money::fromRupees('100.00'), Money::fromRupees('10.00'), false), // payout 100
        ];
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $repo->method('getBySellerId')->willReturnMap([
            [11, $this->activeSubmerchant('KEYA')],
            [12, $this->activeSubmerchant('KEYB')],
        ]);

        $plan = $this->provider($this->orderMock(799.20, '000000045'), $shares, $repo)->buildForOrder(45);

        $this->assertSame(45, $plan->orderId());
        $this->assertSame('000000045', $plan->orderIncrementId());
        $segments = $plan->result()->vendorSegments();
        $this->assertSame('650.00', $segments[11]->toRupeeString());
        $this->assertSame('100.00', $segments[12]->toRupeeString());
        $this->assertSame('49.20', $plan->result()->platformAmount()->toRupeeString());
        $this->assertSame([11 => 'KEYA', 12 => 'KEYB'], $plan->sellerKeyMap());
    }

    public function testTaxStaysWithPlatformWhenTaxToSellerIsFalse(): void
    {
        $shares = [new SellerShare(11, Money::fromRupees('100.00'), Money::fromRupees('20.00'), false)];
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $repo->method('getBySellerId')->willReturn($this->activeSubmerchant('KEYA'));

        // grand total 130 = 100 item + 20 tax + 10 shipping; vendor gets item only.
        $plan = $this->provider($this->orderMock(130.00, '000000046'), $shares, $repo)->buildForOrder(46);

        $this->assertSame('100.00', $plan->result()->vendorSegments()[11]->toRupeeString());
        $this->assertSame('30.00', $plan->result()->platformAmount()->toRupeeString());
    }

    public function testThrowsWhenAVendorIsNotMapped(): void
    {
        $shares = [new SellerShare(99, Money::fromRupees('100.00'), Money::zero(), false)];
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $repo->method('getBySellerId')->willReturn(null);

        $this->expectException(SubmerchantNotMappedException::class);
        $this->provider($this->orderMock(120.00, '000000047'), $shares, $repo)->buildForOrder(47);
    }

    public function testThrowsWhenSubmerchantIsInactive(): void
    {
        $inactive = $this->createMock(Submerchant::class);
        $inactive->method('isActive')->willReturn(false);
        $inactive->method('getMerchantKey')->willReturn('KEYA');
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $repo->method('getBySellerId')->willReturn($inactive);

        $shares = [new SellerShare(11, Money::fromRupees('100.00'), Money::zero(), false)];
        $this->expectException(SubmerchantNotMappedException::class);
        $this->provider($this->orderMock(120.00, '000000048'), $shares, $repo)->buildForOrder(48);
    }

    public function testThrowsWhenOrderHasNoPositiveGrandTotal(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getGrandTotal')->willReturn(null); // unpaid / cancelled order
        $order->method('getIncrementId')->willReturn('000000050');
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $shares = [new SellerShare(11, Money::fromRupees('100.00'), Money::zero(), false)];

        $this->expectException(SplitCalculationException::class);
        $this->provider($order, $shares, $repo)->buildForOrder(50);
    }

    public function testZeroPayoutVendorIsSkippedAndNeedsNoMapping(): void
    {
        $shares = [
            new SellerShare(11, Money::fromRupees('500.00'), Money::zero(), false), // payout 500
            new SellerShare(12, Money::zero(), Money::zero(), false),               // payout 0 -> skipped
        ];
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $repo->method('getBySellerId')->willReturnMap([
            [11, $this->activeSubmerchant('KEYA')],
            [12, null], // unmapped, but never consulted because seller 12 is not in the split
        ]);

        $plan = $this->provider($this->orderMock(600.00, '000000049'), $shares, $repo)->buildForOrder(49);

        $this->assertSame([11 => 'KEYA'], $plan->sellerKeyMap());
        $this->assertArrayNotHasKey(12, $plan->result()->vendorSegments());
        $this->assertSame('100.00', $plan->result()->platformAmount()->toRupeeString());
    }

    public function testBuildsPerSellerItemRowsFilteredToSplitVendorsAndReconciling(): void
    {
        // Seller 11 is in the split; seller 12 has a zero payout and is dropped.
        $shares = [
            new SellerShare(11, Money::fromRupees('500.00'), Money::zero(), false),
            new SellerShare(12, Money::zero(), Money::zero(), false),
        ];
        $repo = $this->createMock(SubmerchantRepositoryInterface::class);
        $repo->method('getBySellerId')->willReturn($this->activeSubmerchant('KEYA'));

        $r300 = Money::fromRupees('300.00');
        $r200 = Money::fromRupees('200.00');
        $r30 = Money::fromRupees('30.00');
        $r20 = Money::fromRupees('20.00');
        $z = Money::zero();
        $items = [
            new OrderItemShare(11, 101, 2143, 1, $r300, $r30, $z, false),
            new OrderItemShare(11, 102, 2144, 2, $r200, $r20, $z, false),
            // Seller 12 item must be filtered out (12 is not a split vendor).
            new OrderItemShare(12, 103, 2145, 1, $z, $z, $z, false),
        ];

        $plan = $this->provider($this->orderMock(600.00, '000000060'), $shares, $repo, $items)->buildForOrder(60);

        $sellerItems = $plan->sellerItems();
        $this->assertArrayHasKey(11, $sellerItems);
        $this->assertArrayNotHasKey(12, $sellerItems);
        $this->assertCount(2, $sellerItems[11]);
        $this->assertSame(101, $sellerItems[11][0]->orderItemId);
        $this->assertSame(102, $sellerItems[11][1]->orderItemId);

        // Invariant: the sum of a seller's per-item payouts equals that seller's segment.
        $itemsSum = $sellerItems[11][0]->payout()->add($sellerItems[11][1]->payout());
        $this->assertSame('500.00', $itemsSum->toRupeeString());
        $this->assertSame('500.00', $plan->result()->vendorSegments()[11]->toRupeeString());
    }
}
