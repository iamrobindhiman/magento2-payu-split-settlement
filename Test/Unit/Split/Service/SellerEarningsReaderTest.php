<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SellerEarnings as SellerEarningsResource;
use RKD\PayuSplit\Split\Model\SellerShare;
use RKD\PayuSplit\Split\Service\SellerEarningsReader;
use PHPUnit\Framework\TestCase;

/**
 * The reader maps Webkul's aggregated earnings rows (decimal strings + the
 * tax_to_seller flag) into typed SellerShare value objects.
 */
class SellerEarningsReaderTest extends TestCase
{
    public function testMapsAggregatedRowsToSellerShares(): void
    {
        $resource = $this->createMock(SellerEarningsResource::class);
        $resource->method('getSellerTotals')->with(45)->willReturn([
            ['seller_id' => '11', 'item_earnings' => '650.0000', 'tax' => '50.0000', 'tax_to_seller' => '1'],
            ['seller_id' => '12', 'item_earnings' => '100.0000', 'tax' => '10.0000', 'tax_to_seller' => '0'],
        ]);

        $shares = (new SellerEarningsReader($resource))->read(45);

        $this->assertCount(2, $shares);
        $this->assertContainsOnlyInstancesOf(SellerShare::class, $shares);
        $this->assertSame(11, $shares[0]->sellerId);
        $this->assertSame('650.00', $shares[0]->itemEarnings->toRupeeString());
        $this->assertSame('50.00', $shares[0]->tax->toRupeeString());
        $this->assertTrue($shares[0]->taxToSeller);
        $this->assertSame(12, $shares[1]->sellerId);
        $this->assertSame('100.00', $shares[1]->itemEarnings->toRupeeString());
        $this->assertFalse($shares[1]->taxToSeller);
    }

    public function testReturnsEmptyArrayWhenOrderHasNoSellerRows(): void
    {
        $resource = $this->createMock(SellerEarningsResource::class);
        $resource->method('getSellerTotals')->willReturn([]);

        $this->assertSame([], (new SellerEarningsReader($resource))->read(999));
    }

    public function testTreatsNullTaxAndFlagAsZeroAndFalse(): void
    {
        $resource = $this->createMock(SellerEarningsResource::class);
        $resource->method('getSellerTotals')->willReturn([
            ['seller_id' => '11', 'item_earnings' => '500.0000', 'tax' => null, 'tax_to_seller' => null],
        ]);

        $shares = (new SellerEarningsReader($resource))->read(1);

        $this->assertSame('0.00', $shares[0]->tax->toRupeeString());
        $this->assertFalse($shares[0]->taxToSeller);
    }
}
