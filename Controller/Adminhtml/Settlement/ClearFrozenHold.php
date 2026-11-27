<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Settlement;

use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\FrozenHoldClearer;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Break-glass: clear a FROZEN hold on a segment so it re-enters the normal release/refund scans
 * (gate item #11 — the recovery half of the frozen-hold lifecycle). The money-critical action —
 * ACL-gated by the dedicated manage resource, CSRF-protected by the admin URL key, and audited.
 * It issues NO PayU call and moves NO money: {@see FrozenHoldClearer} re-checks the out-of-band
 * credit-memo condition and REFUSES if it still holds, and the downstream release/refund gates
 * re-check every condition and re-freeze if it persists. Gated OFF by default until operators are
 * trained.
 */
class ClearFrozenHold extends Action
{
    /**
     * @see RearmRefund::ADMIN_RESOURCE
     */
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::settlement_manage';

    /**
     * @param Context $context
     * @param FrozenHoldClearer $holdClearer
     * @param SettlementAuditLogger $auditLogger
     * @param SplitConfig $splitConfig
     * @param AuditLogResource $auditLogResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly FrozenHoldClearer $holdClearer,
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

        // Master kill-switch — a money-critical recovery action opts in per store.
        if (!$this->splitConfig->isClearHoldEnabled()) {
            $this->messageManager->addErrorMessage(
                __('Clear FROZEN hold is currently disabled. Enable it in the settlement config first.')
            );
            return $redirect;
        }

        // The hold removal and its audit row commit atomically (one transaction): a clear can never
        // persist without an accountability row, and an audit failure rolls back the clear rather
        // than leaving a silent un-audited money-state mutation.
        $connection = $this->auditLogResource->getConnection();
        $connection->beginTransaction();
        try {
            $cleared = $this->holdClearer->clear($segmentId);

            $this->auditLogger->record(
                AuditLog::ACTION_CLEAR_HOLD,
                AuditLog::ENTITY_SEGMENT,
                $segmentId,
                $orderId,
                'FROZEN',
                'CLEARED',
                sprintf('removed %d FROZEN hold row(s)', $cleared)
            );

            $connection->commit();
            $this->messageManager->addSuccessMessage(
                __('FROZEN hold cleared for segment %1; it will be re-evaluated on the next scan.', $segmentId)
            );
        } catch (LocalizedException $e) {
            // A refusal (no hold / credit memo still present) — surface the reason, no state change.
            $connection->rollBack();
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('RKD_PayuSplit: clear-hold failed for segment ' . $segmentId . ': ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Could not clear the hold. Please check the logs.'));
        }

        return $redirect;
    }
}
