<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Per-item gating row under a segment. Carries the item's own delivery date and
 * return window so a multi-item vendor's segment can be gated correctly (the
 * segment releases only once all its items are delivered + past their windows).
 */
class SplitSegmentItem extends AbstractModel
{
    public const RETURN_NONE = 'none';
    public const RETURN_OPEN = 'open';
    public const RETURN_REFUNDED = 'refunded';
    public const RETURN_REPLACED = 'replaced';
    public const RETURN_REJECTED = 'rejected';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitSegmentItemResource::class);
    }

    /**
     * @return int
     */
    public function getSegmentId(): int
    {
        return (int) $this->getData('segment_id');
    }

    /**
     * @return int
     */
    public function getOrderItemId(): int
    {
        return (int) $this->getData('order_item_id');
    }

    /**
     * @return string|null
     */
    public function getDeliveredAt(): ?string
    {
        $value = $this->getData('delivered_at');
        return $value === null ? null : (string) $value;
    }

    /**
     * @return string|null
     */
    public function getWindowClosesAt(): ?string
    {
        $value = $this->getData('window_closes_at');
        return $value === null ? null : (string) $value;
    }

    /**
     * The customer's frozen paid vendor-payout for this line, in paise (matrix M-2:
     * the actual paid amount after discounts, snapshotted at payment time — never the
     * live list price). The vendor leg of a var8 refund for this item.
     *
     * @return int
     */
    public function getItemAmountPaise(): int
    {
        return (int) $this->getData('item_amount_paise');
    }

    /**
     * The marketplace commission on this line, in paise (snapshotted). The
     * aggregatorRefundAmount (parent-commission) leg of a var8 refund for this item.
     *
     * @return int
     */
    public function getItemCommissionPaise(): int
    {
        return (int) $this->getData('item_commission_paise');
    }

    /**
     * @return string
     */
    public function getReturnState(): string
    {
        return (string) ($this->getData('return_state') ?: self::RETURN_NONE);
    }

    /**
     * Project this row into the pure gating value object the eligibility check reads.
     *
     * @return ItemGate
     */
    public function toGate(): ItemGate
    {
        return new ItemGate($this->getDeliveredAt(), $this->getWindowClosesAt(), $this->getReturnState());
    }
}
