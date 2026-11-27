<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Submerchant;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin → Sales → PayU Submerchants: the seller → PayU child-merchant mapping grid (A7-2).
 */
class Index extends Action
{
    /**
     * ACL resource gating the mapping admin pages. (The Loki data ops — load/save — ride the
     * shared Loki_AdminComponents::grid resource; this gates the page views.)
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
        $resultPage->getConfig()->getTitle()->prepend(__('PayU Submerchants'));
        return $resultPage;
    }
}
