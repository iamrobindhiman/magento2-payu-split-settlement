<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Settlement;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin → Sales → PayU Settlements: the read-only per-vendor settlement grid (A7-1).
 */
class Index extends Action
{
    /**
     * ACL resource gating this controller.
     */
    public const ADMIN_RESOURCE = 'RKD_PayuSplit::settlements';

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
        $resultPage->setActiveMenu('RKD_PayuSplit::settlements');
        $resultPage->getConfig()->getTitle()->prepend(__('PayU Settlements'));
        return $resultPage;
    }
}
