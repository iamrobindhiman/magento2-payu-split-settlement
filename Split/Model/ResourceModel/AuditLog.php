<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the rkd_payu_audit_log table.
 */
class AuditLog extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_audit_log', 'entity_id');
    }
}
