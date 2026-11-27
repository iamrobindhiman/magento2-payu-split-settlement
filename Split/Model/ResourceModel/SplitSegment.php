<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for rkd_payu_split_segment (one per (order, seller); 0 = parent).
 */
class SplitSegment extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_split_segment', 'segment_id');
    }
}
