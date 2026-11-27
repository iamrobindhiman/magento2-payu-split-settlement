<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\PayoutSwitchHistory;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

/**
 * Staff "Reactivate payouts": undoes a "Deactivate payouts", and nothing else (wave-4 integrity F3).
 *
 * It restores a mapping staff switched off; it never switches on one that was never on — first
 * activation of an onboarded vendor is not a click here. So it acts only when the latest on/off
 * action in the audit log for this seller is a Deactivate. Makes no PayU call.
 *
 * ACL-gated by the manage resource, CSRF-protected by the admin URL key, audited in the same
 * transaction as the change.
 */
class Reactivate extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param SubmerchantRepositoryInterface $repository
     * @param PayoutSwitchHistory $switchHistory
     * @param SettlementAuditLogger $auditLogger
     * @param AuditLogResource $auditLogResource
     * @param LoggerInterface $logger
     * @param SubmerchantResource $submerchantResource
     */
    public function __construct(
        Context $context,
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly PayoutSwitchHistory $switchHistory,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly AuditLogResource $auditLogResource,
        private readonly LoggerInterface $logger,
        private readonly SubmerchantResource $submerchantResource
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('rkd_payu/submerchant/index');

        $sellerId = (int)$this->getRequest()->getParam('seller_id');
        $row = $this->repository->getBySellerId($sellerId);
        // The history decides, and it ends at a reset or a link: so this only ever switches back on the
        // account the row had when staff switched it off (wave-7 integrity F2).
        if ($row === null || $row->isActive() || !$this->switchHistory->isSwitchedOffByStaff((int)$row->getId())) {
            $this->messageManager->addErrorMessage(__(
                'Seller %1 was not switched off with "Deactivate payouts" on its current PayU account, so there '
                . 'is nothing to reactivate.',
                $sellerId
            ));
            return $redirect;
        }

        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            if (!$this->switchOnIfUnchanged($row)) {
                $connection->rollBack();
                $this->messageManager->addErrorMessage(__(
                    'Seller %1 changed while this was being checked. Reload the grid and try again.',
                    $sellerId
                ));
                return $redirect;
            }
            $this->auditLogger->record(
                AuditLog::ACTION_REACTIVATE,
                AuditLog::ENTITY_SUBMERCHANT_ROW,
                (int)$row->getId(),
                null,
                'inactive',
                'active',
                'seller ' . $sellerId
            );
            $connection->commit();
            $this->messageManager->addSuccessMessage(__('Payouts to seller %1 are switched back on.', $sellerId));
        } catch (\Throwable $t) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: reactivate failed for seller ' . $sellerId . ': ' . get_class($t));
            $this->messageManager->addErrorMessage(__('Could not reactivate the mapping. Please check the logs.'));
        }

        return $redirect;
    }

    /**
     * Compare-and-set on exactly what was checked: still off, same state, same account. A reset or link
     * landing in between leaves this writing nothing (wave-7 integrity F5).
     *
     * @param Submerchant $row
     * @return bool
     */
    private function switchOnIfUnchanged(Submerchant $row): bool
    {
        $uuid = $row->getPayuUuid();
        $key = $row->hasMerchantKey() ? $row->getMerchantKey() : null;
        $connection = $this->submerchantResource->getConnection();
        return (int)$connection->update(
            $this->submerchantResource->getMainTable(),
            ['is_active' => 1],
            [
                'entity_id = ?' => (int)$row->getId(),
                'is_active = ?' => 0,
                'onboarding_state = ?' => $row->getOnboardingState(),
                // Spelled out: a NULL bound into a placeholder is quoted as '' and would never match.
                $uuid === null ? 'payu_uuid IS NULL' : 'payu_uuid = ?' => $uuid,
                $key === null ? "(payu_merchant_key IS NULL OR payu_merchant_key = '')" : 'payu_merchant_key = ?' => $key,
            ]
        ) === 1;
    }
}
