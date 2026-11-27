<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold as SplitHoldResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One active hold blocking a segment's release (rkd_payu_segment_hold). A segment with ANY
 * hold row is not releasable (INV — SegmentEligibility blocks on a non-empty hold-type set).
 * Holds are removed when their condition clears (return resolved, KYC restored). Unique on
 * (segment_id, hold_type) so a hold of a given type is recorded at most once per segment.
 */
class SplitHold extends AbstractModel
{
    /** Customer return is open against an item in the segment. */
    public const TYPE_RETURN_OPEN = 'RETURN_OPEN';
    /** A replacement is in flight; release waits for the replacement to deliver + clear. */
    public const TYPE_REPLACEMENT = 'REPLACEMENT';
    /** The vendor's PayU submerchant KYC is suspended — PayU would reject the release. */
    public const TYPE_KYC_SUSPENDED = 'KYC_SUSPENDED';
    /** An operator placed a manual hold. */
    public const TYPE_MANUAL = 'MANUAL';
    /**
     * The post-release readback did not match the expected net (INV-18). Auto-processing is
     * frozen for this segment pending manual reconciliation — never auto-released again.
     */
    public const TYPE_FROZEN = 'FROZEN';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitHoldResource::class);
    }

    /**
     * @return int
     */
    public function getSegmentId(): int
    {
        return (int) $this->getData('segment_id');
    }

    /**
     * @return string
     */
    public function getHoldType(): string
    {
        return (string) $this->getData('hold_type');
    }
}
