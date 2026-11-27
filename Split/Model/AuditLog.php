<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One immutable audit row for a break-glass PayU settlement admin action (A7-3): who did what,
 * to which split/segment, and the state before/after. Append-only — never updated.
 */
class AuditLog extends AbstractModel
{
    public const ACTION_RETRY_SPLIT = 'retry_split';
    public const ACTION_REARM_REFUND = 'rearm_refund';
    public const ACTION_CLEAR_HOLD = 'clear_hold';

    public const ENTITY_SPLIT = 'split';
    public const ENTITY_SEGMENT = 'segment';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(AuditLogResource::class);
    }
}
