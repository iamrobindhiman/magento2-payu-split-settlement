<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Exception\PayuOnboardingCredentialsException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Service\LegacyMappingReset;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Staff "Reset legacy mapping": clear a hand-typed key that names no PayU account, and queue the
 * vendor for onboarding. Every check lives in LegacyMappingReset; this turns its answer into a message.
 *
 * ACL-gated by the manage resource, CSRF-protected by the admin URL key, audited by the service in
 * the same transaction as the write. Both PayU calls it causes are reads.
 */
class ResetLegacy extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param LegacyMappingReset $reset
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly LegacyMappingReset $reset,
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
        try {
            $this->reset->reset($sellerId);
            $this->messageManager->addSuccessMessage(__(
                'Seller %1\'s old mapping is cleared and payouts are off. The vendor is queued: onboarding will '
                . 'create their PayU account on its next run.',
                $sellerId
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (PayuOnboardingCredentialsException $e) {
            $this->messageManager->addErrorMessage(__(
                'PayU refused our onboarding credentials. Check the client id, secret and scopes in '
                . 'Stores > Configuration.'
            ));
        } catch (PayuOnboardingRequestException $e) {
            $this->messageManager->addErrorMessage(__('Could not read PayU to check the mapping. Try again shortly.'));
        } catch (\Throwable $t) {
            $this->logger->error('RKD_PayuSplit: legacy reset failed for seller ' . $sellerId . ': ' . get_class($t));
            $this->messageManager->addErrorMessage(__('Could not reset the mapping. Please check the logs.'));
        }

        return $redirect;
    }
}
