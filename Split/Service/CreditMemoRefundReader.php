<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\ResourceModel\OrderCreditMemoRefunds;
use RKD\PayuSplit\Split\Model\ResourceModel\OrderItemPaidAmount;

/**
 * Sums the amount already refunded out-of-band via Magento credit memos
 * (sales_order_item.base_amount_refunded) for a set of order lines, in integer paise.
 *
 * On a split-managed order, customer refunds are issued through the var8 split-refund path
 * (RefundExecutor), which never writes a Magento credit memo. So a non-zero value here is, by
 * construction, an OUT-OF-BAND admin credit memo the split engine did not process — the gate item
 * #5 signal. The release path consults this to FREEZE an otherwise-releasable vendor segment:
 * release_settlement disburses the WHOLE held submerchant balance (no amount param), so releasing a
 * segment whose customer was already refunded out-of-band would over-pay the vendor relative to the
 * customer's true net payment, and drift the Webkul ledger.
 *
 * Reads Magento's immutable sales_order_item totals via paise-safe Money — never a float.
 */
class CreditMemoRefundReader
{
    /**
     * @param OrderItemPaidAmount $resource
     * @param OrderCreditMemoRefunds $orderResource
     */
    public function __construct(
        private readonly OrderItemPaidAmount $resource,
        private readonly OrderCreditMemoRefunds $orderResource
    ) {
    }

    /**
     * @param int[] $orderItemIds The segment's order lines
     * @return int Total amount credit-memo-refunded for those lines, paise (0 when none)
     */
    public function refundedPaiseForItems(array $orderItemIds): int
    {
        if ($orderItemIds === []) {
            return 0;
        }

        $total = Money::zero();
        foreach ($this->resource->getRefundedRows($orderItemIds) as $row) {
            $total = $total->add(Money::fromRupees((string) ($row['refunded'] ?? '0')));
        }

        return $total->paise;
    }

    /**
     * Order-level out-of-band refund (base_adjustment_positive + base_shipping_refunded across the
     * order's credit memos), integer paise. This is the refund NOT attributable to any item line —
     * so {@see self::refundedPaiseForItems()} misses it, and the item-less parent commission segment
     * would otherwise never see it. Gate item #5's F3 extension: the release gate sums this with the
     * per-item amount and freezes on any non-zero total, so an adjustment-only / shipping-only credit
     * memo can no longer let a segment release over money the customer was already refunded.
     *
     * ORDER-WIDE BY DESIGN (gate item #18, accepted 2026-07-05): adjustment_positive / shipping are
     * order-level sales_creditmemo fields with no seller/segment attribution, so this is deliberately
     * NOT vendor-scoped — ANY order-level out-of-band refund freezes EVERY non-terminal segment of
     * the order (release side via F3, refund side via F5). In a multi-vendor order that means one
     * vendor's ad-hoc shipping/adjustment memo also freezes a sibling vendor's release/refund. That
     * blast radius is the money-safe choice: an unattributable refund could relate to any segment, so
     * freeze-all-and-let-a-human-reconcile can never over/under-pay — the alternative (letting one
     * through) risks a real over-pay. Narrowing is structurally blocked (the fields aren't
     * seller-keyed); do not "fix" it to per-seller without re-opening the money review.
     *
     * @param int $orderId sales_order.entity_id
     * @return int paise (0 when none)
     */
    public function orderLevelRefundPaise(int $orderId): int
    {
        $total = Money::zero();
        foreach ($this->orderResource->getOrderRefundRows($orderId) as $row) {
            $total = $total->add(Money::fromRupees((string) ($row['adjustment_positive'] ?? '0')));
            $total = $total->add(Money::fromRupees((string) ($row['shipping_refunded'] ?? '0')));
        }

        return $total->paise;
    }
}
