<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Service\CreateMarkForgetter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Staff "Forget create mark" — every rule, and the transaction around the write and its audit row, lives
 * in CreateMarkForgetter; this turns the answer into a message. The only PayU call it can cause is a
 * read, made before any transaction opens (wave-9 security L-3).
 *
 * ACL-gated by the manage resource, CSRF-protected by the admin URL key.
 */
class ForgetMark extends Action
{
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * @param Context $context
     * @param CreateMarkForgetter $forgetter
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly CreateMarkForgetter $forgetter,
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
            $this->forgetter->forget($sellerId);
            $this->messageManager->addSuccessMessage(__(
                'The recorded PayU create for seller %1 is cleared. If they are FAILED, press Retry to onboard them.',
                $sellerId
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (PayuOnboardingRequestException $e) {
            $this->messageManager->addErrorMessage(__('Could not read PayU to check the record. Try again shortly.'));
        } catch (\Throwable $t) {
            $this->logger->error('RKD_PayuSplit: forget-mark failed for seller ' . $sellerId . ': ' . get_class($t));
            $this->messageManager->addErrorMessage(__('Could not clear the record. Please check the logs.'));
        }

        return $redirect;
    }
}
