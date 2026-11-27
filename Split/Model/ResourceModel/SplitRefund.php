<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for rkd_payu_refund (one row per var8 refund leg).
 */
class SplitRefund extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_refund', 'refund_id');
    }
}
