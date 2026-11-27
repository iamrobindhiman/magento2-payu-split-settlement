<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\SplitOrder;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitOrder as SplitOrderResource;
use RKD\PayuSplit\Split\Model\SplitOrder as SplitOrderModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_split rows (the per-order lifecycle headers). The
 * split-trigger scan selects the unfinished ones (PENDING / REQUESTED).
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SplitOrderModel::class, SplitOrderResource::class);
    }
}
