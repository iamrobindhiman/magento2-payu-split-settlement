<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\Source;

use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the settlement grid's "Type" filter. Values come straight from the
 * SplitSegment type constants so the dropdown can never drift from the stored values.
 */
class SegmentType implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('All')],
            ['value' => SplitSegment::TYPE_VENDOR, 'label' => __('Vendor')],
            ['value' => SplitSegment::TYPE_COMMISSION, 'label' => __('Commission')],
        ];
    }
}
