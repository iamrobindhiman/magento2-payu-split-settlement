<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\OrderItemEarnings as OrderItemEarningsResource;
use RKD\PayuSplit\Split\Service\OrderItemEarningsReader;
use PHPUnit\Framework\TestCase;

/**
 * The reader turns Webkul's per-item marketplace_saleslist rows into typed
 * OrderItemShare value objects. The payout rule mirrors the per-seller reader:
 * tax is added to the vendor's payout only when tax_to_seller is set.
 */
class OrderItemEarningsReaderTest extends TestCase
{
    public function testMapsRowsToTypedItemShares(): void
    {
        $resource = $this->createMock(OrderItemEarningsResource::class);
        $resource->method('getItemRows')->with(45)->willReturn([
            [
                'order_item_id' => '52', 'mageproduct_id' => '2143', 'seller_id' => '20',
                'qty' => '2', 'item_earnings' => '1150.7200', 'commission' => '287.6800',
                'tax' => '0.0000', 'tax_to_seller' => '0',
            ],
            [
                'order_item_id' => '53', 'mageproduct_id' => '2100', 'seller_id' => '18',
                'qty' => '1', 'item_earnings' => '500.00', 'commission' => '50.00',
                'tax' => '20.00', 'tax_to_seller' => '1',
            ],
        ]);

        $items = (new OrderItemEarningsReader($resource))->read(45);

        $this->assertCount(2, $items);

        $this->assertSame(20, $items[0]->sellerId);
        $this->assertSame(52, $items[0]->orderItemId);
        $this->assertSame(2143, $items[0]->mageproductId);
        $this->assertSame(2, $items[0]->qty);
        $this->assertSame('1150.72', $items[0]->itemEarnings->toRupeeString());
        $this->assertSame('287.68', $items[0]->commission->toRupeeString());
        // tax_to_seller=0 -> payout excludes tax
        $this->assertSame('1150.72', $items[0]->payout()->toRupeeString());

        // tax_to_seller=1 -> payout includes tax (500 + 20)
        $this->assertTrue($items[1]->taxToSeller);
        $this->assertSame('520.00', $items[1]->payout()->toRupeeString());
    }

    public function testFlagsANonIntegerQuantityLine(): void
    {
        // #22: magequantity is a varchar. A non-integer ordered qty (e.g. a decimal-qty product)
        // truncates DOWN to the segment's int qty, which — combined with the delivered-side truncation
        // — would let the delivery gate release before all units arrive. The reader flags such a line so
        // the split-record path can FREEZE the segment. The qty itself stays the truncated int.
        $resource = $this->createMock(OrderItemEarningsResource::class);
        $resource->method('getItemRows')->willReturn([
            [
                'order_item_id' => '52', 'mageproduct_id' => '2143', 'seller_id' => '20',
                'qty' => '2.5000', 'item_earnings' => '250.00', 'commission' => '0.00',
                'tax' => '0.00', 'tax_to_seller' => '0',
            ],
            [
                'order_item_id' => '53', 'mageproduct_id' => '2100', 'seller_id' => '20',
                'qty' => '2', 'item_earnings' => '200.00', 'commission' => '0.00',
                'tax' => '0.00', 'tax_to_seller' => '0',
            ],
        ]);

        $items = (new OrderItemEarningsReader($resource))->read(45);

        $this->assertTrue($items[0]->hasFractionalQty, 'a 2.5 qty line must be flagged');
        $this->assertSame(2, $items[0]->qty, 'qty stays the truncated int');
        $this->assertFalse($items[1]->hasFractionalQty, 'an integer 2 qty line is not flagged');
    }

    public function testEmptyOrderYieldsNoItems(): void
    {
        $resource = $this->createMock(OrderItemEarningsResource::class);
        $resource->method('getItemRows')->willReturn([]);

        $this->assertSame([], (new OrderItemEarningsReader($resource))->read(99));
    }
}
