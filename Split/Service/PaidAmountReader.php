<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\ResourceModel\OrderItemPaidAmount;

/**
 * Computes the customer's prorated PAID amount in paise for a set of order lines, the
 * matrix M-2 input to the refund calculator. Sums (base_row_total_incl_tax −
 * base_discount_amount) per line via paise-safe Money — the actual amount the customer
 * paid, never the pre-discount list price G_v (the S-36 anti-pattern). Reads Magento's
 * immutable sales_order_item totals (snapshot-safe), never the live Webkul saleslist.
 */
class PaidAmountReader
{
    /**
     * @param OrderItemPaidAmount $resource
     */
    public function __construct(
        private readonly OrderItemPaidAmount $resource
    ) {
    }

    /**
     * @param int[] $orderItemIds The cancelled/returned order lines
     * @return int Total customer-paid amount for those lines, paise
     */
    public function paidPaiseForItems(array $orderItemIds): int
    {
        if ($orderItemIds === []) {
            return 0;
        }

        $total = Money::zero();
        foreach ($this->resource->getPaidRows($orderItemIds) as $row) {
            // Customer's net paid for the line: row total incl tax, less the row's allocated
            // discount, less anything already refunded via a Magento credit memo (so a
            // var8 never double-refunds a portion the credit-memo path already returned).
            $paid = Money::fromRupees((string) ($row['paid_incl_tax'] ?? '0'))
                ->subtract(Money::fromRupees((string) ($row['discount'] ?? '0')))
                ->subtract(Money::fromRupees((string) ($row['refunded'] ?? '0')));
            $total = $total->add($paid);
        }

        return $total->paise;
    }
}
