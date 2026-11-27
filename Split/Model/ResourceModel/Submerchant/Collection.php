<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\Submerchant;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\Submerchant as SubmerchantModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_submerchant rows.
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(SubmerchantModel::class, SubmerchantResource::class);
    }
}
