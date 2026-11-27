<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\OnboardingQueue;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Staff record that they sent the Schedule C e-sign email from PayU's dashboard (R-4, T-12).
 * PayU has no API for it, so the only evidence it happened is this click: who and when are
 * stored on the vendor and in the audit log. The vendor then leaves the "send e-sign" queue.
 */
class MarkEsignSent extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param OnboardingQueue $queue
     * @param AuthSession $authSession
     * @param SettlementAuditLogger $auditLogger
     * @param AuditLogResource $auditLogResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly OnboardingQueue $queue,
        private readonly AuthSession $authSession,
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
        $user = $this->authSession->getUser();
        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            $this->queue->markEsignSent($sellerId, $user ? (int)$user->getId() : 0);
            $this->auditLogger->record(
                AuditLog::ACTION_ESIGN_SENT,
                AuditLog::ENTITY_SUBMERCHANT,
                $sellerId,
                null,
                Submerchant::STATE_AWAITING_ESIGN,
                Submerchant::STATE_AWAITING_ESIGN,
                'Schedule C e-sign email sent from the PayU dashboard'
            );
            $connection->commit();
            $this->messageManager->addSuccessMessage(
                __('Recorded: the Schedule C e-sign was sent for seller %1.', $sellerId)
            );
        } catch (LocalizedException $e) {
            $connection->rollBack();
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $t) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: mark e-sign sent failed for seller ' . $sellerId . ': ' . $t->getMessage());
            $this->messageManager->addErrorMessage(__('Could not record the e-sign. Please check the logs.'));
        }

        return $redirect;
    }
}
