<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Settlement;

use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\RefundRearmer;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Break-glass: re-arm a FROZEN (HELD) refund on a segment so the refund scan re-selects it and
 * the executor re-fires on the same intent token (A7-3). The money-critical action — ACL-gated
 * by the dedicated manage resource, CSRF-protected by the admin URL key, and audited. It issues
 * NO PayU call itself, and {@see RefundRearmer} refuses any segment with a SUCCESS/QUEUED/PENDING
 * leg so it can never double-refund a completed or in-flight refund.
 */
class RearmRefund extends Action
{
    /**
     * @see RetrySplit::ADMIN_RESOURCE
     */
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::settlement_manage';

    /**
     * @param Context $context
     * @param RefundRearmer $refundRearmer
     * @param SettlementAuditLogger $auditLogger
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly RefundRearmer $refundRearmer,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly SplitConfig $splitConfig,
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

        $segmentId = (int) $this->getRequest()->getParam('segment_id');
        $orderId = (int) $this->getRequest()->getParam('order_id') ?: null;

        // Gated OFF until PayU confirms duplicate-var2-on-rejection semantics (H-6); the money
        // review found re-arm-refund's double-refund safety rests on that unproven behaviour.
        if (!$this->splitConfig->isRearmRefundEnabled()) {
            $this->messageManager->addErrorMessage(
                __('Re-arm refund is currently disabled (pending PayU confirmation). Contact engineering.')
            );
            return $redirect;
        }

        // The state change and its audit row commit atomically (one transaction): a re-arm can
        // never persist without an accountability row, and an audit failure rolls back the
        // re-arm rather than leaving a silent un-audited money-state mutation.
        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            $voided = $this->refundRearmer->rearm($segmentId);

            $this->auditLogger->record(
                AuditLog::ACTION_REARM_REFUND,
                AuditLog::ENTITY_SEGMENT,
                $segmentId,
                $orderId,
                'HELD/FAILED',
                'REARMED',
                sprintf('voided %d refund leg(s)', $voided)
            );

            $connection->commit();
            $this->messageManager->addSuccessMessage(
                __('Refund for segment %1 re-armed; it will be re-attempted on the next scan.', $segmentId)
            );
        } catch (LocalizedException $e) {
            $connection->rollBack();
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: re-arm-refund failed for segment ' . $segmentId . ': ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Could not re-arm the refund. Please check the logs.'));
        }

        return $redirect;
    }
}
