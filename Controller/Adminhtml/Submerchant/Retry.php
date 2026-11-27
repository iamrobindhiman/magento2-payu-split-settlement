<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\ExistingChildFinder;
use RKD\PayuSplit\Split\Service\OnboardingQueue;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Staff "Retry" on a vendor whose PayU onboarding FAILED (R-8). Puts the vendor back to
 * QUEUED; the next scan picks it up. Makes no PayU call itself, and the retry is safe by
 * construction: the worker adopts a child PayU already holds and reads PayU's bank counter
 * before ever sending a bank.
 *
 * ACL-gated by the manage resource, CSRF-protected by the admin URL key, audited in the same
 * transaction as the state change.
 */
class Retry extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param OnboardingQueue $queue
     * @param SettlementAuditLogger $auditLogger
     * @param AuditLogResource $auditLogResource
     * @param LoggerInterface $logger
     * @param SubmerchantRepositoryInterface $repository
     * @param ExistingChildFinder $childFinder
     */
    public function __construct(
        Context $context,
        private readonly OnboardingQueue $queue,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly AuditLogResource $auditLogResource,
        private readonly LoggerInterface $logger,
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly ExistingChildFinder $childFinder
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
        $emptyParent = $this->parentHasNoChildrenFor($sellerId);
        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            $this->queue->retry($sellerId, $emptyParent);
            $this->auditLogger->record(
                AuditLog::ACTION_ONBOARDING_RETRY,
                AuditLog::ENTITY_SUBMERCHANT,
                $sellerId,
                null,
                Submerchant::STATE_FAILED,
                Submerchant::STATE_QUEUED,
                $emptyParent ? 'PayU reports no sub-merchants under our parent; create-sent mark cleared' : null
            );
            $connection->commit();
            $this->messageManager->addSuccessMessage(
                __('Seller %1 queued again; PayU onboarding will be retried on the next scan.', $sellerId)
            );
        } catch (LocalizedException $e) {
            $connection->rollBack();
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $t) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: onboarding retry failed for seller ' . $sellerId . ': ' . $t->getMessage());
            $this->messageManager->addErrorMessage(__('Could not queue the retry. Please check the logs.'));
        }

        return $redirect;
    }

    /**
     * Only for a vendor with no PayU account whose create may be outstanding: ask PayU (a read)
     * whether our parent has any sub-merchants at all. Any doubt — PayU unreachable — means no.
     *
     * @param int $sellerId
     * @return bool
     */
    private function parentHasNoChildrenFor(int $sellerId): bool
    {
        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null || $row->getPayuUuid() !== null || $row->getPayuEmail() === null) {
            return false;
        }
        // Give a late create time to show up in PayU's listing before trusting an empty one
        // (wave-5 integrity F4).
        $lastAttempt = strtotime((string)$row->getData('last_attempt_at') . ' UTC');
        if ($lastAttempt === false || $lastAttempt > time() - Submerchant::CREATE_SETTLE_SECONDS) {
            return false;
        }
        try {
            return $this->childFinder->parentHasNoChildren();
        } catch (\Throwable $t) {
            $this->logger->warning('RKD_PayuSplit: retry could not read the PayU listing: ' . get_class($t));
            return false;
        }
    }
}
