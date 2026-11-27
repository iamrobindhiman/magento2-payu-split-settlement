<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The data-layer output for one order's split (Slice A2): the validated
 * SplitResult plus the resolved seller_id -> PayU child merchant key map.
 * It carries everything the downstream payload builder (A3) needs except the
 * actual PayU call. Immutable.
 */
class OrderSplitPlan
{
    /**
     * @param int $orderId Magento order entity id
     * @param string $orderIncrementId Human-facing increment id (e.g. 000000045)
     * @param SplitResult $result Validated vendor segments + platform remainder
     * @param array<int, string> $sellerKeyMap seller_id => PayU child merchant key
     * @param array<int, OrderItemShare[]> $sellerItems seller_id => per-item shares, vendor segments only (default empty)
     */
    public function __construct(
        private readonly int $orderId,
        private readonly string $orderIncrementId,
        private readonly SplitResult $result,
        private readonly array $sellerKeyMap,
        private readonly array $sellerItems = []
    ) {
    }

    /**
     * @return int
     */
    public function orderId(): int
    {
        return $this->orderId;
    }

    /**
     * @return string
     */
    public function orderIncrementId(): string
    {
        return $this->orderIncrementId;
    }

    /**
     * @return SplitResult
     */
    public function result(): SplitResult
    {
        return $this->result;
    }

    /**
     * @return array<int, string>
     */
    public function sellerKeyMap(): array
    {
        return $this->sellerKeyMap;
    }

    /**
     * Per-seller item shares for the vendor segments (seller_id => OrderItemShare[]).
     * Empty when the plan was built without item detail.
     *
     * @return array<int, OrderItemShare[]>
     */
    public function sellerItems(): array
    {
        return $this->sellerItems;
    }
}
