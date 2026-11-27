<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

/**
 * Staff "Deactivate payouts": the off switch for a vendor's PayU mapping (wave-3 M2).
 *
 * is_active is what the split reads before routing a vendor's share, and the edit form refuses
 * rows onboarding manages — so without this, a linked or onboarded vendor could not be switched
 * off except in SQL. It only ever turns routing OFF; nothing here turns it on. Makes no PayU call.
 *
 * ACL-gated by the manage resource, CSRF-protected by the admin URL key, audited in the same
 * transaction as the change.
 */
class Deactivate extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param SubmerchantRepositoryInterface $repository
     * @param SettlementAuditLogger $auditLogger
     * @param AuditLogResource $auditLogResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly AuditLogResource $auditLogResource,
        private readonly LoggerInterface $logger
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
        if ($row === null || !$row->isActive()) {
            $this->messageManager->addErrorMessage(__('Seller %1 has no active PayU mapping to deactivate.', $sellerId));
            return $redirect;
        }

        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            $row->setData('is_active', 0);
            $this->repository->save($row);
            $this->auditLogger->record(
                AuditLog::ACTION_DEACTIVATE,
                AuditLog::ENTITY_SUBMERCHANT_ROW,
                (int)$row->getId(),
                null,
                'active',
                'inactive',
                'seller ' . $sellerId
            );
            $connection->commit();
            $this->messageManager->addSuccessMessage(__(
                'Payouts to seller %1 are switched off: new orders will not route their share to PayU.',
                $sellerId
            ));
        } catch (\Throwable $t) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: deactivate failed for seller ' . $sellerId . ': ' . get_class($t));
            $this->messageManager->addErrorMessage(__('Could not deactivate the mapping. Please check the logs.'));
        }

        return $redirect;
    }
}
