<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Controller\Adminhtml\Audit;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin → Sales → PayU Settlement Audit: the read-only break-glass audit-log grid (A7-3).
 */
class Index extends Action
{
    /**
     * Viewing the audit trail is part of settlement visibility (read resource).
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
        $resultPage->setActiveMenu('RKD_PayuSplit::audit');
        $resultPage->getConfig()->getTitle()->prepend(__('PayU Settlement Audit'));
        return $resultPage;
    }
}
