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
    public const ACTION_ONBOARDING_RETRY = 'onboarding_retry';
    public const ACTION_ESIGN_SENT = 'onboarding_esign_sent';
    public const ACTION_ONBOARDING_LINK = 'onboarding_link';
    /** A legacy row linked to the account its own key already named — same account, not a new one. */
    public const ACTION_ONBOARDING_LINK_LEGACY = 'onboarding_link_legacy';
    public const ACTION_DEACTIVATE = 'submerchant_deactivate';
    public const ACTION_REACTIVATE = 'submerchant_reactivate';
    public const ACTION_LEGACY_RESET = 'submerchant_legacy_reset';
    public const ACTION_FORGET_MARK = 'onboarding_forget_create_mark';

    public const ENTITY_SPLIT = 'split';
    public const ENTITY_SEGMENT = 'segment';
    public const ENTITY_SUBMERCHANT = 'submerchant';
    /** A submerchant ROW, by entity_id — for switches that belong to the row, not the seller. */
    public const ENTITY_SUBMERCHANT_ROW = 'submerchant_row';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(AuditLogResource::class);
    }
}
