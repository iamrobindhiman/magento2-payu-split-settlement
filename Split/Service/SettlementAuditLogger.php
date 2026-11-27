<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\AuditLogFactory;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use Magento\Backend\Model\Auth\Session as AuthSession;

/**
 * Records one immutable audit row per break-glass PayU settlement action (A7-3), stamping the
 * acting admin from the backend session. Every manual money-operation recovery (retry split,
 * re-arm refund) writes one — it is the accountability trail. The row's timestamp is owned by
 * the schema (created_at DEFAULT CURRENT_TIMESTAMP) so there is no date()/time() here.
 */
class SettlementAuditLogger
{
    /**
     * @param AuditLogFactory $auditLogFactory
     * @param AuditLogResource $auditLogResource
     * @param AuthSession $authSession
     */
    public function __construct(
        private readonly AuditLogFactory $auditLogFactory,
        private readonly AuditLogResource $auditLogResource,
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * @param string $action One of AuditLog::ACTION_*
     * @param string $entityType One of AuditLog::ENTITY_*
     * @param int $entityRefId The acted-on split/segment id
     * @param int|null $orderId Magento order id (context), if known
     * @param string|null $beforeState
     * @param string|null $afterState
     * @param string|null $note
     * @return void
     */
    public function record(
        string $action,
        string $entityType,
        int $entityRefId,
        ?int $orderId,
        ?string $beforeState,
        ?string $afterState,
        ?string $note = null
    ): void {
        $user = $this->authSession->getUser();

        $row = $this->auditLogFactory->create();
        $row->addData([
            'admin_user_id' => $user ? (int) $user->getId() : null,
            'admin_user_name' => $user ? (string) $user->getUserName() : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_ref_id' => $entityRefId,
            'order_id' => $orderId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'note' => $note,
        ]);
        $this->auditLogResource->save($row);
    }
}
