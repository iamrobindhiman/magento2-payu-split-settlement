<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Plugin\Loki;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * Loki's AJAX endpoint checks only Loki_Components::index, and the client chooses which layout
 * handles to load and which blocks to render. So any admin allowed to use one Loki grid could
 * render another module's grid or form — the PayU submerchant form with its raw merchant key, the
 * settlement and audit grids, the Shiprocket pickup grid — without that page's own permission
 * (wave-4 security F2).
 *
 * A first line only — LokiBlockGuard is the boundary. Checked on the layout Loki actually built, not on what the client sent (wave-5 security F1): Loki
 * strips characters out of each posted handle, and adds a default handle made from a route the
 * client also chooses, so a check on the posted strings can be walked round both ways. Every handle
 * on the built layout that matches a registered prefix needs that ACL resource, before anything is
 * dispatched or rendered. Modules register prefixes through di.xml ("handle prefix" => resource).
 */
class LokiReadGuard
{
    /**
     * @param AuthorizationInterface $authorization
     * @param LoggerInterface $logger
     * @param array<string, string> $handleResources Handle prefix => ACL resource
     * @param string[] $openHandlePrefixes Loki pages any Loki user may load (no extra ACL)
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly LoggerInterface $logger,
        private readonly array $handleResources = [],
        private readonly array $openHandlePrefixes = []
    ) {
    }

    /**
     * @param object $subject Loki\Components\Util\Controller\LayoutLoader
     * @param LayoutInterface $layout
     * @return LayoutInterface
     * @throws AuthorizationException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterLoad(object $subject, LayoutInterface $layout): LayoutInterface
    {
        foreach ($layout->getUpdate()->getHandles() as $handle) {
            // A real handle is only these characters. Anything else is an attempt to inject into the
            // layout merger's XPath through the client-set route (wave-6 security H-1).
            if (preg_match('/^[a-z0-9_-]+$/', (string)$handle) !== 1) {
                $this->logger->warning('Loki read refused: malformed layout handle');
                throw new AuthorizationException(__('You are not allowed to view this page.'));
            }
            if (!$this->isKnownHandle((string)$handle)) {
                // Deny by default: Loki's endpoint checks only Loki_Components::index, so any handle it is
                // allowed to load is a page every Loki user can read — core grids included (the customer
                // list, sales orders). Only the handles our Loki pages actually use are served (wave-8 security F1).
                $this->logger->warning('Loki read refused: handle ' . $handle . ' is not a registered Loki page');
                throw new AuthorizationException(__('You are not allowed to view this page.'));
            }
            $resource = $this->resourceFor((string)$handle);
            if ($resource !== null && !$this->authorization->isAllowed($resource)) {
                $this->logger->warning('Loki read refused: handle ' . $handle . ' needs ' . $resource);
                throw new AuthorizationException(__('You are not allowed to view this page.'));
            }
        }
        return $layout;
    }

    /**
     * @param string $handle
     * @return bool
     */
    private function isKnownHandle(string $handle): bool
    {
        if ($handle === 'default') {
            return true;
        }
        foreach (array_merge(array_keys($this->handleResources), $this->openHandlePrefixes) as $prefix) {
            if (str_starts_with($handle, (string)$prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $handle
     * @return string|null
     */
    private function resourceFor(string $handle): ?string
    {
        foreach ($this->handleResources as $prefix => $resource) {
            if (str_starts_with(strtolower($handle), (string)$prefix)) {
                return (string)$resource;
            }
        }
        return null;
    }
}
