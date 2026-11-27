<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Settlement;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use RKD\PayuSplit\Split\Service\SplitRearmer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Break-glass: re-arm a FAILED split header so the next trigger scan re-attempts it (A7-3).
 * ACL-gated by the dedicated manage resource, CSRF-protected by the admin URL key, and every
 * invocation is audited. Issues NO PayU call — it only flips state; the safe re-attempt rides
 * the reviewed SplitRequester (get_split_info first → no double split).
 */
class RetrySplit extends Action
{
    /**
     * Break-glass ACL — separate from the read-only grid resource.
     */
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::settlement_manage';

    /**
     * @param Context $context
     * @param SplitOrderRepositoryInterface $splitOrderRepository
     * @param SplitRearmer $splitRearmer
     * @param SettlementAuditLogger $auditLogger
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly SplitOrderRepositoryInterface $splitOrderRepository,
        private readonly SplitRearmer $splitRearmer,
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
        $redirect->setPath('rkd_payu/settlement/index');

        $orderId = (int) $this->getRequest()->getParam('order_id');

        // The state change and its audit row commit atomically (one transaction) — no re-arm
        // without an accountability row.
        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            $header = $this->splitOrderRepository->getByOrderId($orderId);
            if ($header === null) {
                throw new LocalizedException(__('No PayU split found for order %1.', $orderId));
            }

            $before = $header->getSplitState();
            $this->splitRearmer->rearm($header);

            $this->auditLogger->record(
                AuditLog::ACTION_RETRY_SPLIT,
                AuditLog::ENTITY_SPLIT,
                (int) $header->getId(),
                $orderId,
                $before,
                $header->getSplitState(),
                null
            );

            $connection->commit();
            $this->messageManager->addSuccessMessage(
                __('Split for order %1 re-armed; it will be re-attempted on the next scan.', $orderId)
            );
        } catch (LocalizedException $e) {
            $connection->rollBack();
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: retry-split failed for order ' . $orderId . ': ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Could not re-arm the split. Please check the logs.'));
        }

        return $redirect;
    }
}
