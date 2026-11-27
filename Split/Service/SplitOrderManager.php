<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Exception\SubmerchantNotMappedException;
use RKD\PayuSplit\Split\Model\OrderItemShare;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitOrderFactory;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentFactory;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Model\SplitSegmentItemFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Records a paid order's split plan into the per-vendor state model (Slice A4).
 *
 * On the first genuine payment, it snapshots the computed split — header + one
 * segment per vendor (+ the marketplace parent commission segment) + per-item
 * gating rows — all in PENDING. The snapshot is frozen here (matrix INV-8): once
 * recorded, later Webkul saleslist mutation cannot change what we will split.
 *
 * Idempotent (unique order_id + an explicit pre-check), and fail-loud-but-safe:
 * an unmapped vendor records a SKIPPED header, a calculation error a FAILED one,
 * so a bad order never silently vanishes.
 */
class SplitOrderManager
{
    /**
     * @param OrderSplitDataProvider $dataProvider
     * @param SplitOrderRepositoryInterface $repository
     * @param SplitOrderFactory $splitOrderFactory
     * @param SplitSegmentFactory $segmentFactory
     * @param SplitSegmentResource $segmentResource
     * @param SplitSegmentItemFactory $itemFactory
     * @param SplitSegmentItemResource $itemResource
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param SegmentHoldWriter $holdWriter
     */
    public function __construct(
        private readonly OrderSplitDataProvider $dataProvider,
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly SplitOrderFactory $splitOrderFactory,
        private readonly SplitSegmentFactory $segmentFactory,
        private readonly SplitSegmentResource $segmentResource,
        private readonly SplitSegmentItemFactory $itemFactory,
        private readonly SplitSegmentItemResource $itemResource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly SegmentHoldWriter $holdWriter
    ) {
    }

    /**
     * Record the split plan for a paid order. No-op if already recorded.
     *
     * @param OrderInterface $order
     * @return void
     */
    public function recordPlan(OrderInterface $order): void
    {
        $orderId = (int) $order->getEntityId();

        if ($this->repository->getByOrderId($orderId) !== null) {
            return; // already recorded — idempotent
        }

        try {
            $plan = $this->dataProvider->buildForOrder($orderId);
        } catch (SubmerchantNotMappedException $e) {
            $this->saveTerminalHeader($order, SplitOrder::STATE_SKIPPED, $e->getMessage());
            return;
        } catch (SplitCalculationException $e) {
            $this->saveTerminalHeader($order, SplitOrder::STATE_FAILED, $e->getMessage());
            return;
        }

        $result = $plan->result();
        $header = $this->newHeader($order, $result->grandTotal()->paise, SplitOrder::STATE_PENDING, null);
        $this->repository->save($header);
        $splitId = (int) $header->getId();

        foreach ($result->vendorSegments() as $sellerId => $amount) {
            $sellerId = (int) $sellerId;
            $segmentId = $this->saveSegment(
                $splitId,
                $orderId,
                $sellerId,
                SplitSegment::TYPE_VENDOR,
                $amount->paise,
                $plan->sellerKeyMap()[$sellerId] ?? null
            );
            $hasFractionalQty = false;
            foreach ($plan->sellerItems()[$sellerId] ?? [] as $item) {
                $this->saveItem($segmentId, $item);
                if ($item->hasFractionalQty) {
                    $hasFractionalQty = true;
                }
            }
            // #22: a non-integer ordered qty truncates DOWN into segment_item.qty, and the delivered
            // side truncates too — so the delivery gate would release before all units arrive. There is
            // no whole-unit product in this catalog, so this is a defensive fence: FREEZE the segment
            // (never auto-releases; also holds the parent via parent-last) and summon a human. Fail-safe.
            if ($hasFractionalQty) {
                $this->freezeFractionalQty($segmentId, $orderId, $sellerId);
            }
        }

        // Marketplace parent (commission) segment — seller_id 0. Releases last and
        // has no per-item delivery gate in v1 (matrix INV-14 / parent-last).
        $this->saveSegment(
            $splitId,
            $orderId,
            0,
            SplitSegment::TYPE_COMMISSION,
            $result->platformAmount()->paise,
            null
        );

        $this->logger->info(sprintf(
            'RKD_PayuSplit: recorded split plan for order %d — %d vendor segment(s) + parent, txn %d paise.',
            $orderId,
            count($result->vendorSegments()),
            $result->grandTotal()->paise
        ));
    }

    /**
     * @param OrderInterface $order
     * @param int $txnPaise
     * @param string $state
     * @param string|null $lastError
     * @return SplitOrder
     */
    private function newHeader(OrderInterface $order, int $txnPaise, string $state, ?string $lastError): SplitOrder
    {
        /** @var SplitOrder $header */
        $header = $this->splitOrderFactory->create();
        $header->setOrderId((int) $order->getEntityId());
        $header->setSplitState($state);
        $header->setData('order_increment_id', (string) $order->getIncrementId());
        $header->setData('mihpayid', $this->extractMihpayid($order));
        // O-9 full-unwind make-whole basis: the customer-paid shipping, snapshotted at split in
        // paise (store currency, matching the grand total). Use shipping INCL tax — the parent
        // segment embeds shipping-incl-tax (grand total carries it and vendor payouts never take
        // shipping or its tax), so the make-whole must equal what the customer actually paid for
        // shipping, else a taxed-shipping order silently under-refunds. Free shipping / null → 0.
        $rawShipping = $order->getShippingInclTax();
        $header->setData(
            'shipping_paise',
            $rawShipping === null ? 0 : \RKD\PayuSplit\Split\Model\Money::fromRupees((string) $rawShipping)->paise
        );
        $header->setData('txn_amount_paise', $txnPaise);
        $header->setData('paid_at', $this->dateTime->gmtDate());
        if ($lastError !== null) {
            $header->setData('last_error', $lastError);
        }
        return $header;
    }

    /**
     * @param OrderInterface $order
     * @param string $state
     * @param string $error
     * @return void
     */
    private function saveTerminalHeader(OrderInterface $order, string $state, string $error): void
    {
        $rawTotal = $order->getGrandTotal();
        $txnPaise = ($rawTotal === null) ? 0 : (int) round((float) $rawTotal * 100);
        $header = $this->newHeader($order, $txnPaise, $state, $error);
        $this->repository->save($header);
        $this->logger->warning(sprintf(
            'RKD_PayuSplit: split plan for order %d marked %s — %s',
            (int) $order->getEntityId(),
            $state,
            $error
        ));
    }

    /**
     * @param int $splitId
     * @param int $orderId
     * @param int $sellerId
     * @param string $type
     * @param int $amountPaise
     * @param string|null $childKey
     * @return int New segment_id
     */
    private function saveSegment(
        int $splitId,
        int $orderId,
        int $sellerId,
        string $type,
        int $amountPaise,
        ?string $childKey
    ): int {
        $segment = $this->segmentFactory->create();
        $segment->setData([
            'split_id' => $splitId,
            'order_id' => $orderId,
            'seller_id' => $sellerId,
            'segment_type' => $type,
            'amount_paise' => $amountPaise,
            'child_merchant_key' => $childKey,
            'state' => SplitSegment::STATE_PENDING,
        ]);
        $this->segmentResource->save($segment);
        return (int) $segment->getId();
    }

    /**
     * Freeze a vendor segment that carries a non-integer-quantity line and raise a CRITICAL alarm
     * (gate item #22). A durable TYPE_FROZEN hold — the same posture the release/refund credit-memo
     * freezes use — so the #11a StaleFrozenSegments age-alarm and the #11b clear-hold break-glass
     * resolve it uniformly. A frozen segment never auto-releases and (being non-terminal) holds the
     * parent commission via parent-last, so nothing downstream over-pays. Idempotent via the hold's
     * unique (segment_id, hold_type) index, and recordPlan itself runs at most once per order.
     *
     * @param int $segmentId
     * @param int $orderId
     * @param int $sellerId
     * @return void
     */
    private function freezeFractionalQty(int $segmentId, int $orderId, int $sellerId): void
    {
        $this->holdWriter->place(
            $segmentId,
            SplitHold::TYPE_FROZEN,
            'Non-integer ordered quantity on a split line — the per-unit delivery gate cannot settle it '
            . 'safely (would truncate and over-release). Segment frozen for manual settlement (gate item #22).'
        );
        $this->logger->critical(sprintf(
            'RKD_PayuSplit: segment %d (order %d, seller %d) FROZEN at split-record — a line has a non-integer '
            . 'ordered quantity (decimal-qty product), which the delivery-qty gate cannot settle safely. '
            . 'Manual reconciliation required (gate item #22).',
            $segmentId,
            $orderId,
            $sellerId
        ));
    }

    /**
     * @param int $segmentId
     * @param OrderItemShare $item
     * @return void
     */
    private function saveItem(int $segmentId, OrderItemShare $item): void
    {
        $row = $this->itemFactory->create();
        $row->setData([
            'segment_id' => $segmentId,
            'order_item_id' => $item->orderItemId,
            'mageproduct_id' => $item->mageproductId,
            'item_amount_paise' => $item->payout()->paise,
            'item_commission_paise' => $item->commission->paise,
            'qty' => $item->qty,
            'return_state' => SplitSegmentItem::RETURN_NONE,
        ]);
        $this->itemResource->save($row);
    }

    /**
     * @param OrderInterface $order
     * @return string|null
     */
    private function extractMihpayid(OrderInterface $order): ?string
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }
        $info = $payment->getAdditionalInformation();
        if (!is_array($info) || !isset($info['payu_mihpayid']) || $info['payu_mihpayid'] === '') {
            return null;
        }
        return (string) $info['payu_mihpayid'];
    }
}
