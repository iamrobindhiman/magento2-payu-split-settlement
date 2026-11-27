<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Plugin\Loki;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * The real read boundary for Loki's AJAX endpoint (wave-6 security H-1).
 *
 * Checking layout HANDLES cannot be the boundary: the client sets the route, Magento turns it into
 * the default handle, and the layout merger puts that handle into an XPath expression unescaped — so
 * a handle that looks harmless can merge a protected page's XML. What cannot be disguised is what the
 * built layout CONTAINS. So before Loki renders anything, and before it dispatches an update to a
 * component, every block on the layout is checked: a block whose data provider is a registered class,
 * or whose name has a registered prefix, needs that page's ACL resource. However the XML got merged.
 * Modules register providers and block-name prefixes through di.xml.
 */
class LokiBlockGuard
{
    /**
     * @param AuthorizationInterface $authorization
     * @param LoggerInterface $logger
     * @param array<string, string> $providerResources Provider class prefix => ACL resource
     * @param array<string, string> $blockResources Block name prefix => ACL resource
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly LoggerInterface $logger,
        private readonly array $providerResources = [],
        private readonly array $blockResources = []
    ) {
    }

    /**
     * @param object $subject Loki\Components\Util\Controller\TargetRenderer
     * @param LayoutInterface $layout
     * @param array<int, mixed> $targetNames
     * @return null
     * @throws AuthorizationException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeRender(object $subject, LayoutInterface $layout, array $targetNames)
    {
        $this->assertAllowed($layout);
        return null;
    }

    /**
     * @param object $subject Loki\Components\Util\Controller\RepositoryDispatcher
     * @param object $component Loki ComponentInterface
     * @param mixed $componentData
     * @return null
     * @throws AuthorizationException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeDispatch(object $subject, object $component, mixed $componentData = null)
    {
        $block = method_exists($component, 'getBlock') ? $component->getBlock() : null;
        if (!is_object($block) || !method_exists($block, 'getLayout')) {
            // Cannot see what it would touch: refuse rather than guess (wave-7 security I-1).
            throw new AuthorizationException(__('You are not allowed to view this page.'));
        }
        $this->assertAllowed($block->getLayout());
        return null;
    }

    /**
     * @param LayoutInterface $layout
     * @return void
     * @throws AuthorizationException
     */
    private function assertAllowed(LayoutInterface $layout): void
    {
        foreach ($layout->getAllBlocks() as $name => $block) {
            // EVERY matching rule must pass, not just the first (wave-7 security I-1).
            foreach ($this->resourcesFor((string)$name, $this->providerOf($block)) as $resource) {
                if (!$this->authorization->isAllowed($resource)) {
                    $this->logger->warning('Loki render refused: block ' . $name . ' needs ' . $resource);
                    throw new AuthorizationException(__('You are not allowed to view this page.'));
                }
            }
        }
    }

    /**
     * @param object $block
     * @return string
     */
    private function providerOf(object $block): string
    {
        $provider = method_exists($block, 'getData') ? $block->getData('provider') : null;
        if (is_object($provider)) {
            return get_class($provider);
        }
        return is_string($provider) ? ltrim(trim($provider), '\\') : '';
    }

    /**
     * The most specific rule by provider, and the most specific by block name — both must pass when
     * they differ (wave-7 security I-1). "Most specific" (longest prefix), not "every match": the
     * generic RKD_PayuSplit rules also match the submerchant blocks, and must not demand the settlements
     * permission of an admin who may only manage submerchants.
     *
     * @param string $blockName
     * @param string $provider
     * @return string[]
     */
    private function resourcesFor(string $blockName, string $provider): array
    {
        $resources = [
            $provider === '' ? null : $this->mostSpecific($this->providerResources, $provider),
            $this->mostSpecific($this->blockResources, $blockName),
        ];
        return array_values(array_unique(array_filter($resources, static fn ($r): bool => $r !== null)));
    }

    /**
     * @param array<string, string> $rules Prefix => ACL resource
     * @param string $subject
     * @return string|null
     */
    private function mostSpecific(array $rules, string $subject): ?string
    {
        $best = null;
        $bestLength = -1;
        foreach ($rules as $prefix => $resource) {
            $prefix = (string)$prefix;
            if (str_starts_with($subject, $prefix) && strlen($prefix) > $bestLength) {
                $best = (string)$resource;
                $bestLength = strlen($prefix);
            }
        }
        return $best;
    }
}
