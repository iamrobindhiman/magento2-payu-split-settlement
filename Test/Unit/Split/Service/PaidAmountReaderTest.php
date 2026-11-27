<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\OrderItemPaidAmount;
use RKD\PayuSplit\Split\Service\PaidAmountReader;
use PHPUnit\Framework\TestCase;

/**
 * The paid-amount reader sources the customer's prorated PAID amount for a set of order
 * lines from Magento's IMMUTABLE sales_order_item totals (base_row_total_incl_tax minus
 * base_discount_amount) — the locked M-2 source. Order item totals are frozen by Magento
 * at placement, so reading them is snapshot-safe (INV-8 concerns the live Webkul
 * saleslist, not these). Returns integer paise; never a float.
 */
class PaidAmountReaderTest extends TestCase
{
    public function testSumsCustomerPaidAcrossItemsInPaise(): void
    {
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->method('getPaidRows')->with([52, 53])->willReturn([
            ['item_id' => '52', 'paid_incl_tax' => '60.0000', 'discount' => '6.6700'],
            ['item_id' => '53', 'paid_incl_tax' => '60.0000', 'discount' => '0.0000'],
        ]);

        // (6000 - 667) + (6000 - 0) = 5333 + 6000 = 11333p.
        $paid = (new PaidAmountReader($resource))->paidPaiseForItems([52, 53]);

        $this->assertSame(11333, $paid);
    }

    public function testSingleDiscountedItemMatchesS36ProratedPaid(): void
    {
        // S-36: a 667p discount on a ₹60 line → customer paid 5,333p (NOT the 6,000 list).
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->method('getPaidRows')->with([52])->willReturn([
            ['item_id' => '52', 'paid_incl_tax' => '60.0000', 'discount' => '6.6700'],
        ]);

        $this->assertSame(5333, (new PaidAmountReader($resource))->paidPaiseForItems([52]));
    }

    public function testNetsOutAmountAlreadyRefundedViaCreditMemo(): void
    {
        // If a Magento credit memo already refunded ₹20 of a ₹60 line, the var8 must only
        // refund the remaining ₹40 — never double-refund the credit-memo'd portion (H-5).
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->method('getPaidRows')->with([52])->willReturn([
            ['item_id' => '52', 'paid_incl_tax' => '60.0000', 'discount' => '0.0000', 'refunded' => '20.0000'],
        ]);

        $this->assertSame(4000, (new PaidAmountReader($resource))->paidPaiseForItems([52]));
    }

    public function testNoItemsYieldsZeroWithoutQuerying(): void
    {
        $resource = $this->createMock(OrderItemPaidAmount::class);
        $resource->expects($this->never())->method('getPaidRows');

        $this->assertSame(0, (new PaidAmountReader($resource))->paidPaiseForItems([]));
    }
}
