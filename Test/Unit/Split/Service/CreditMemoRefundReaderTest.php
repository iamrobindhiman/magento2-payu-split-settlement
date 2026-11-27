<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\OrderCreditMemoRefunds;
use RKD\PayuSplit\Split\Model\ResourceModel\OrderItemPaidAmount;
use RKD\PayuSplit\Split\Service\CreditMemoRefundReader;
use PHPUnit\Framework\TestCase;

/**
 * Reads the total amount already refunded out-of-band via Magento credit-memos
 * (sales_order_item.base_amount_refunded) for a set of order lines, in integer paise.
 *
 * On a split-managed order, customer refunds are issued through the var8 split-refund path,
 * which never writes a Magento credit-memo — so ANY base_amount_refunded here is an out-of-band
 * admin credit-memo the split engine did not process. The release path uses this to FREEZE an
 * otherwise-releasable segment (gate item #5): releasing the full held amount would over-pay the
 * vendor relative to the customer's true net payment. Returns integer paise; never a float.
 */
class CreditMemoRefundReaderTest extends TestCase
{
    public function testOrderLevelRefundSumsAdjustmentAndShippingInPaise(): void
    {
        // F3: adjustment_positive + shipping refunds across the order's credit memos — the
        // out-of-band refund NOT attributable to any item line.
        $itemResource = $this->createMock(OrderItemPaidAmount::class);
        $orderResource = $this->createMock(OrderCreditMemoRefunds::class);
        $orderResource->method('getOrderRefundRows')->with(42)->willReturn([
            ['adjustment_positive' => '30.0000', 'shipping_refunded' => '0.0000'],
            ['adjustment_positive' => '0.0000', 'shipping_refunded' => '60.0000'],
        ]);
        // 3000 + 6000 = 9000p.
        $this->assertSame(9000, (new CreditMemoRefundReader($itemResource, $orderResource))->orderLevelRefundPaise(42));
    }

    public function testOrderLevelRefundZeroWhenNoMemos(): void
    {
        $itemResource = $this->createMock(OrderItemPaidAmount::class);
        $orderResource = $this->createMock(OrderCreditMemoRefunds::class);
        $orderResource->method('getOrderRefundRows')->with(42)->willReturn([]);
        $this->assertSame(0, (new CreditMemoRefundReader($itemResource, $orderResource))->orderLevelRefundPaise(42));
    }

    public function testOrderLevelRefundTreatsMissingColumnsAsZero(): void
    {
        $itemResource = $this->createMock(OrderItemPaidAmount::class);
        $orderResource = $this->createMock(OrderCreditMemoRefunds::class);
        $orderResource->method('getOrderRefundRows')->with(42)->willReturn([
            ['adjustment_positive' => null], // shipping column absent, adjustment null
        ]);
        $this->assertSame(0, (new CreditMemoRefundReader($itemResource, $orderResource))->orderLevelRefundPaise(42));
    }

    public function testSumsCreditMemoRefundedAcrossItemsInPaise(): void
    {
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->method('getRefundedRows')->with([52, 53])->willReturn([
            ['item_id' => '52', 'refunded' => '20.0000'],
            ['item_id' => '53', 'refunded' => '5.5000'],
        ]);

        // 2000 + 550 = 2550p.
        $this->assertSame(2550, (new CreditMemoRefundReader($resource, $this->createMock(OrderCreditMemoRefunds::class)))->refundedPaiseForItems([52, 53]));
    }

    public function testZeroWhenNothingRefunded(): void
    {
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->method('getRefundedRows')->with([52])->willReturn([
            ['item_id' => '52', 'refunded' => '0.0000'],
        ]);

        $this->assertSame(0, (new CreditMemoRefundReader($resource, $this->createMock(OrderCreditMemoRefunds::class)))->refundedPaiseForItems([52]));
    }

    public function testMissingOrNullRefundedTreatedAsZero(): void
    {
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->method('getRefundedRows')->with([52, 53])->willReturn([
            ['item_id' => '52'],                       // column absent
            ['item_id' => '53', 'refunded' => null],   // null
        ]);

        $this->assertSame(0, (new CreditMemoRefundReader($resource, $this->createMock(OrderCreditMemoRefunds::class)))->refundedPaiseForItems([52, 53]));
    }

    public function testNoItemsYieldsZeroWithoutQuerying(): void
    {
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->expects($this->never())->method('getRefundedRows');

        $this->assertSame(0, (new CreditMemoRefundReader($resource, $this->createMock(OrderCreditMemoRefunds::class)))->refundedPaiseForItems([]));
    }
}
