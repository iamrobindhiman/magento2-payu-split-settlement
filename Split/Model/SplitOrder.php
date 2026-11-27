<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitOrder as SplitOrderResource;
use Magento\Framework\Model\AbstractModel;

/**
 * The per-order split lifecycle header (rkd_payu_split). One row per order; the
 * split state machine lives here, while delivery/return/release state lives at the
 * segment + item grain.
 */
class SplitOrder extends AbstractModel
{
    public const STATE_PENDING = 'PENDING';
    public const STATE_ELIGIBLE = 'ELIGIBLE';
    public const STATE_REQUESTED = 'REQUESTED';
    public const STATE_DONE = 'DONE';
    public const STATE_NOT_REQUIRED = 'NOT_REQUIRED';
    public const STATE_FAILED = 'FAILED';
    public const STATE_SKIPPED = 'SKIPPED';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitOrderResource::class);
    }

    /**
     * @return int
     */
    public function getOrderId(): int
    {
        return (int) $this->getData('order_id');
    }

    /**
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self
    {
        return $this->setData('order_id', $orderId);
    }

    /**
     * @return string
     */
    public function getOrderIncrementId(): string
    {
        return (string) $this->getData('order_increment_id');
    }

    /**
     * @return string|null PayU transaction id (mihpayid); null until the gateway records it
     */
    public function getMihpayid(): ?string
    {
        $mihpayid = $this->getData('mihpayid');
        return ($mihpayid === null || $mihpayid === '') ? null : (string) $mihpayid;
    }

    /**
     * @return int Captured transaction total, in paise
     */
    public function getTxnAmountPaise(): int
    {
        return (int) $this->getData('txn_amount_paise');
    }

    /**
     * @return int Customer-paid shipping snapshotted at split (the O-9 full-unwind make-whole), paise
     */
    public function getShippingPaise(): int
    {
        return (int) $this->getData('shipping_paise');
    }

    /**
     * @return string
     */
    public function getSplitState(): string
    {
        return (string) $this->getData('split_state');
    }

    /**
     * @param string $state
     * @return $this
     */
    public function setSplitState(string $state): self
    {
        return $this->setData('split_state', $state);
    }
}
