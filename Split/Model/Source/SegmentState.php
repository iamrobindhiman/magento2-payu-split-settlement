<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\Source;

use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the settlement grid's "State" filter. Values come straight from the
 * SplitSegment state constants so the dropdown can never drift from the stored values.
 */
class SegmentState implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('All')],
            ['value' => SplitSegment::STATE_PENDING, 'label' => __('Pending')],
            ['value' => SplitSegment::STATE_AWAITING_DELIVERY, 'label' => __('Awaiting Delivery')],
            ['value' => SplitSegment::STATE_IN_WINDOW, 'label' => __('In Return Window')],
            ['value' => SplitSegment::STATE_ELIGIBLE, 'label' => __('Eligible')],
            ['value' => SplitSegment::STATE_RELEASE_REQUESTED, 'label' => __('Release Requested')],
            ['value' => SplitSegment::STATE_RELEASED, 'label' => __('Released')],
            ['value' => SplitSegment::STATE_CANCELLED, 'label' => __('Cancelled')],
            ['value' => SplitSegment::STATE_REFUND_PENDING, 'label' => __('Refund Pending')],
            ['value' => SplitSegment::STATE_PARTIAL_REFUNDED, 'label' => __('Partially Refunded')],
            ['value' => SplitSegment::STATE_REFUNDED, 'label' => __('Refunded')],
            ['value' => SplitSegment::STATE_WRITTEN_OFF, 'label' => __('Written Off')],
            ['value' => SplitSegment::STATE_NOT_IN_SPLIT, 'label' => __('Not In Split')],
        ];
    }
}
