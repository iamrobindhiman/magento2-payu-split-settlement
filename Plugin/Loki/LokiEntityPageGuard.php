<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Plugin\Loki;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Psr\Log\LoggerInterface;

/**
 * Loki's own admin entity page (loki_admin_components/index/entity) implements HttpGetActionInterface
 * without extending the backend Action, so Magento's admin authentication and its ADMIN_RESOURCE never
 * run: it answers without a login. Its layout is a grid over the customer collection. Today a Webkul
 * observer happens to crash it first; this closes it on purpose (wave-7 security, outside-lens finding).
 */
class LokiEntityPageGuard
{
    private const RESOURCE = 'Loki_AdminComponents::grid';

    /**
     * @param AdminSession $adminSession
     * @param AuthorizationInterface $authorization
     * @param ForwardFactory $forwardFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AdminSession $adminSession,
        private readonly AuthorizationInterface $authorization,
        private readonly ForwardFactory $forwardFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param object $subject Loki\AdminComponents\Controller\Adminhtml\Index\Entity
     * @param callable $proceed
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(object $subject, callable $proceed)
    {
        if ($this->adminSession->isLoggedIn() && $this->authorization->isAllowed(self::RESOURCE)) {
            return $proceed();
        }
        $this->logger->warning('Loki entity page refused: no admin session or permission');
        return $this->forwardFactory->create()->forward('noroute');
    }
}
