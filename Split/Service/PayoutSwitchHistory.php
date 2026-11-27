<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog\CollectionFactory;

/**
 * Whether staff deliberately switched a vendor's PayU payouts off, read from the audit log.
 *
 * A mapping switched off with "Deactivate payouts" is switched back on only with "Reactivate
 * payouts" — audited — never by the edit form, where a form opened before the Deactivate would
 * otherwise silently undo it (wave-4 correctness F5, integrity F3).
 */
class PayoutSwitchHistory
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Keyed by the mapping ROW, not the seller: a row re-pointed at another seller keeps its history,
     * and a new row for the same seller does not inherit an old row's (wave-5 integrity F3).
     *
     * @param int $entityId rkd_payu_submerchant.entity_id
     * @return bool True when the latest on/off action on this row is a Deactivate
     */
    public function isSwitchedOffByStaff(int $entityId): bool
    {
        $latest = $this->collectionFactory->create()
            ->addFieldToFilter('entity_type', AuditLog::ENTITY_SUBMERCHANT_ROW)
            ->addFieldToFilter('entity_ref_id', $entityId)
            // A legacy reset or a link puts a different (or newly tied) account on the row, so each ends the
            // old history too — a Deactivate from before it must not unlock Reactivate on the account after
            // it (wave-6 integrity F2, wave-7 integrity F2).
            ->addFieldToFilter('action', ['in' => [
                AuditLog::ACTION_DEACTIVATE,
                AuditLog::ACTION_REACTIVATE,
                AuditLog::ACTION_LEGACY_RESET,
                AuditLog::ACTION_ONBOARDING_LINK,
            ]])
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();
        return (string)$latest->getData('action') === AuditLog::ACTION_DEACTIVATE;
    }
}
