<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\SplitRefund as SplitRefundModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_refund rows (var8 refund legs).
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitRefundModel::class, SplitRefundResource::class);
    }
}
