<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitSegment as SplitSegmentModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_split_segment rows (the per-(order,seller) segments).
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitSegmentModel::class, SplitSegmentResource::class);
    }
}
