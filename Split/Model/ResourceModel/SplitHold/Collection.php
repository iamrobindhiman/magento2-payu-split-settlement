<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SplitHold;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold as SplitHoldResource;
use RKD\PayuSplit\Split\Model\SplitHold as SplitHoldModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_segment_hold rows (active release holds).
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitHoldModel::class, SplitHoldResource::class);
    }
}
