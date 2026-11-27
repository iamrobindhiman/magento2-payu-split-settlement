<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin → Sales → PayU Submerchants: the create / edit form page for a seller → PayU
 * child-merchant mapping (A7-2). Rendering only — the load and save data ops are handled by
 * Loki's generic component controller; this controller just renders the form page.
 */
class Form extends Action
{
    /**
     * @see Index::ADMIN_RESOURCE
     */
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::submerchants';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page
     */
    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('RKD_PayuSplit::submerchants');

        $title = $this->getRequest()->getParam('id')
            ? __('Edit Submerchant Mapping')
            : __('New Submerchant Mapping');
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
