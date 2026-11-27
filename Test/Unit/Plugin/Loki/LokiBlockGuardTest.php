<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Plugin\Loki;

use RKD\PayuSplit\Plugin\Loki\LokiBlockGuard;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wave-6 security H-1: whatever handle string got a protected page's XML merged, a protected block on
 * the built layout needs that page's permission before anything renders or dispatches.
 */
class LokiBlockGuardTest extends TestCase
{
    public function testAProtectedBlockOnTheLayoutNeedsThePermissionWhateverItsHandle(): void
    {
        $layout = $this->layout(['rkd-payu.submerchant-form' => "\n  RKD\\PayuSplit\\Split\\Model\\ResourceModel\\Submerchant\\Collection\n"]);
        self::assertSame('refused', $this->render($layout, []));
        self::assertSame('refused', $this->render($layout, ['RKD_PayuSplit::settlements']), 'settlements is not enough for the form');
        self::assertSame('allowed', $this->render($layout, ['RKD_PayuSplit::submerchants']));
    }

    /**
     * Wave-7 security I-1: when the provider and the name point at different permissions, both are needed.
     */
    public function testAProviderAndANameThatDisagreeNeedBothPermissions(): void
    {
        $layout = $this->layout(['rkd-payu.submerchant-lookalike' => 'RKD\\PayuSplit\\Split\\Model\\ResourceModel\\SettlementGrid\\Collection']);
        self::assertSame('refused', $this->render($layout, ['RKD_PayuSplit::submerchants']));
        self::assertSame('refused', $this->render($layout, ['RKD_PayuSplit::settlements']));
        self::assertSame('allowed', $this->render($layout, ['RKD_PayuSplit::submerchants', 'RKD_PayuSplit::settlements']));
    }

    public function testADispatchWithNoBlockIsRefused(): void
    {
        $component = new class {
            public function getBlock(): ?object
            {
                return null;
            }
        };
        $this->expectException(AuthorizationException::class);
        $this->guard(['RKD_PayuSplit::submerchants', 'RKD_PayuSplit::settlements'])->beforeDispatch(new DataObject(), $component, []);
    }

    public function testMatchedByProviderEvenUnderAnotherName(): void
    {
        $layout = $this->layout(['renamed' => 'Fab\\ShiprocketMarketplace\\Model\\ResourceModel\\PickupAddress\\Collection']);
        self::assertSame('refused', $this->render($layout, []));
    }

    public function testMatchedByNameEvenWithoutAProvider(): void
    {
        self::assertSame('refused', $this->render($this->layout(['rkd-payu.audit-grid' => null]), []));
    }

    public function testOrdinaryBlocksAreUntouched(): void
    {
        self::assertSame('allowed', $this->render($this->layout(['header' => null, 'x' => 'Magento\\Customer\\Model\\ResourceModel\\Customer\\Collection']), []));
    }

    public function testADispatchToAComponentChecksItsLayoutToo(): void
    {
        $layout = $this->layout(['rkd-payu.settlement-grid' => 'RKD\\PayuSplit\\Split\\Model\\ResourceModel\\SettlementGrid\\Collection']);
        $block = $this->createMock(\Magento\Framework\View\Element\AbstractBlock::class);
        $block->method('getLayout')->willReturn($layout);
        $component = new class ($block) {
            public function __construct(private readonly object $block)
            {
            }

            public function getBlock(): object
            {
                return $this->block;
            }
        };

        $this->expectException(AuthorizationException::class);
        $this->guard([])->beforeDispatch(new DataObject(), $component, []);
    }

    /**
     * @param array<string, string|null> $blocks name => provider
     */
    private function layout(array $blocks): LayoutInterface
    {
        $all = [];
        foreach ($blocks as $name => $provider) {
            $all[$name] = new DataObject($provider === null ? [] : ['provider' => $provider]);
        }
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getAllBlocks')->willReturn($all);
        return $layout;
    }

    /**
     * @param string[] $allowed
     */
    private function render(LayoutInterface $layout, array $allowed): string
    {
        try {
            $this->guard($allowed)->beforeRender(new DataObject(), $layout, []);
            return 'allowed';
        } catch (AuthorizationException $e) {
            return 'refused';
        }
    }

    /**
     * @param string[] $allowed
     */
    private function guard(array $allowed): LokiBlockGuard
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(fn (string $r): bool => in_array($r, $allowed, true));
        return new LokiBlockGuard(
            $authorization,
            $this->createMock(LoggerInterface::class),
            [
                'RKD\\PayuSplit\\Split\\Model\\ResourceModel\\Submerchant' => 'RKD_PayuSplit::submerchants',
                'RKD\\PayuSplit\\' => 'RKD_PayuSplit::settlements',
                'Fab\\ShiprocketMarketplace\\' => 'Fab_ShiprocketMarketplace::pickup_manage',
            ],
            ['rkd-payu.submerchant-' => 'RKD_PayuSplit::submerchants', 'rkd-payu.' => 'RKD_PayuSplit::settlements']
        );
    }
}
