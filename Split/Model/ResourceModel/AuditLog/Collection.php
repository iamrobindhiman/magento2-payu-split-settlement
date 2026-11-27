<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel\AuditLog;

use RKD\PayuSplit\Split\Model\AuditLog as AuditLogModel;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of rkd_payu_audit_log rows (newest first by default for the A7-3 audit grid).
 */
class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(AuditLogModel::class, AuditLogResource::class);
    }
}
