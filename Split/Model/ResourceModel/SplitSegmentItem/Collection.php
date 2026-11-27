<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\SplitSegmentItem as SplitSegmentItemModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_segment_item rows (per-item gating detail under a segment).
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitSegmentItemModel::class, SplitSegmentItemResource::class);
    }
}
