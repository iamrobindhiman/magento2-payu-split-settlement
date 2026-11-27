<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One settlement segment: a (order, seller) pair. seller_id 0 is the marketplace
 * parent (the commission segment). The per-vendor release lifecycle runs here;
 * delivery/return gating is derived from the segment's item rows.
 */
class SplitSegment extends AbstractModel
{
    public const TYPE_VENDOR = 'vendor';
    public const TYPE_COMMISSION = 'commission';

    public const STATE_PENDING = 'PENDING';
    public const STATE_AWAITING_DELIVERY = 'AWAITING_DELIVERY';
    public const STATE_IN_WINDOW = 'IN_WINDOW';
    public const STATE_ELIGIBLE = 'ELIGIBLE';
    public const STATE_RELEASE_REQUESTED = 'RELEASE_REQUESTED';
    public const STATE_RELEASED = 'RELEASED';
    public const STATE_CANCELLED = 'CANCELLED';
    public const STATE_REFUND_PENDING = 'REFUND_PENDING';
    public const STATE_PARTIAL_REFUNDED = 'PARTIAL_REFUNDED';
    public const STATE_REFUNDED = 'REFUNDED';
    public const STATE_WRITTEN_OFF = 'WRITTEN_OFF';
    public const STATE_NOT_IN_SPLIT = 'NOT_IN_SPLIT';

    /**
     * A segment is "done" (no further money movement) in these states. The parent commission
     * segment may release only once EVERY vendor segment of its order is terminal (INV-14).
     */
    public const TERMINAL_STATES = [
        self::STATE_RELEASED,
        self::STATE_CANCELLED,
        self::STATE_REFUNDED,
        self::STATE_WRITTEN_OFF,
    ];

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitSegmentResource::class);
    }

    /**
     * @return int
     */
    public function getSplitId(): int
    {
        return (int) $this->getData('split_id');
    }

    /**
     * @return int
     */
    public function getOrderId(): int
    {
        return (int) $this->getData('order_id');
    }

    /**
     * @return int
     */
    public function getSellerId(): int
    {
        return (int) $this->getData('seller_id');
    }

    /**
     * @return string
     */
    public function getSegmentType(): string
    {
        return (string) $this->getData('segment_type');
    }

    /**
     * @return int
     */
    public function getAmountPaise(): int
    {
        return (int) $this->getData('amount_paise');
    }

    /**
     * @return int
     */
    public function getRefundedPaise(): int
    {
        return (int) $this->getData('refunded_paise');
    }

    /**
     * @return int
     */
    public function getReleasedPaise(): int
    {
        return (int) $this->getData('released_paise');
    }

    /**
     * @return string|null PayU child merchant key, or null/empty when the seller is unmapped
     */
    public function getChildMerchantKey(): ?string
    {
        $key = $this->getData('child_merchant_key');
        return ($key === null || $key === '') ? null : (string) $key;
    }

    /**
     * The numeric PayU child merchant id (MID) — the var2 release_settlement targets (NOT the
     * merchant key). Null/empty when the seller is unmapped.
     *
     * @return string|null
     */
    public function getChildMid(): ?string
    {
        $mid = $this->getData('child_mid');
        return ($mid === null || $mid === '') ? null : (string) $mid;
    }

    /**
     * @return string|null GMT 'Y-m-d H:i:s' the segment was released, or null
     */
    public function getReleasedAt(): ?string
    {
        $value = $this->getData('released_at');
        return $value === null ? null : (string) $value;
    }

    /**
     * The instant the release_settlement was requested (stamped by the ReleaseProcessor CAS
     * claim). The recon uses it as the strand-age anchor: a segment stuck RELEASE_REQUESTED
     * long past the alert threshold is alarmed (a settlement that never reconciled).
     *
     * @return string|null GMT 'Y-m-d H:i:s', or null when never requested
     */
    public function getReleaseRequestedAt(): ?string
    {
        $value = $this->getData('release_requested_at');
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * @return int Count of release attempts fired so far (a ledger-free retry guard)
     */
    public function getReleaseAttempts(): int
    {
        return (int) $this->getData('release_attempts');
    }

    /**
     * @return int Refund claim version: bumped by the refund executor's optimistic-CAS claim (R-1),
     *             so two overlapping cron ticks cannot both fire a var8 for this segment
     */
    public function getRefundAttempts(): int
    {
        return (int) $this->getData('refund_attempts');
    }

    /**
     * The instant the release was mirrored into Webkul's seller ledger (A5c-4). Its presence is
     * the idempotency guard: WebkulSettlementSync skips a segment that already carries it, and the
     * pull-reconcile scan selects RELEASED segments where it IS NULL (a lost release-event
     * self-heals).
     *
     * @return string|null GMT 'Y-m-d H:i:s', or null when not yet mirrored
     */
    public function getWebkulSyncedAt(): ?string
    {
        $value = $this->getData('webkul_synced_at');
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return (string) $this->getData('state');
    }

    /**
     * @param string $state
     * @return $this
     */
    public function setState(string $state): self
    {
        $this->setData('state', $state);
        return $this;
    }
}
