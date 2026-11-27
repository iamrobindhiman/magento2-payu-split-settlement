<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Plugin\Loki;

use RKD\PayuSplit\Plugin\Loki\LokiReadGuard;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Model\Layout\Merge;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Loki must not render a PayU (or pickup) grid or form for an admin who may not open that page —
 * judged on the handles Loki actually loaded (wave-4 security F2, wave-5 security F1).
 */
class LokiReadGuardTest extends TestCase
{
    private const RESOURCES = [
        'rkd_payu_submerchant_' => 'RKD_PayuSplit::submerchants',
        'rkd_payu_' => 'RKD_PayuSplit::settlements',
    ];

    /**
     * The layout's handles are what Loki loaded: "!rkd_payu_submerchant_form" arrives here already
     * stripped, and a route smuggled in through "request" arrives as the default handle.
     */
    public function testAProtectedHandleOnTheBuiltLayoutNeedsThePagesPermission(): void
    {
        self::assertSame('refused', $this->ask(['default', 'rkd_payu_submerchant_form'], []));
        self::assertSame('refused', $this->ask(['rkd_payu_audit_index'], ['RKD_PayuSplit::submerchants']));
        self::assertSame('refused', $this->ask(['default', 'RKD_PAYU_SETTLEMENT_INDEX'], []));
    }

    /**
     * Wave-6 security H-1: a route smuggled in as the default handle carries XPath; real handles never
     * contain anything but [a-z0-9_-].
     */
    public function testAMalformedHandleIsRefusedEvenForAFullAdmin(): void
    {
        $all = ['RKD_PayuSplit::submerchants', 'RKD_PayuSplit::settlements'];
        self::assertSame('refused', $this->ask(['default', "zz' or @id='rkd_payu_submerchant_form"], $all));
        self::assertSame('refused', $this->ask(['default', 'some handle'], $all));
    }

    public function testWithThePermissionItRenders(): void
    {
        self::assertSame('allowed', $this->ask(['default', 'rkd_payu_submerchant_form'], ['RKD_PayuSplit::submerchants']));
        self::assertSame('allowed', $this->ask(['rkd_payu_settlement_index'], ['RKD_PayuSplit::settlements']));
    }

    /**
     * Wave-8 security F1: Loki's endpoint must not serve core pages (the customer list, sales orders) to
     * anyone who merely may use a Loki grid. Only registered Loki pages are served.
     */
    public function testCorePagesAreNeverServedThroughLoki(): void
    {
        $everything = ['RKD_PayuSplit::submerchants', 'RKD_PayuSplit::settlements', 'Magento_Customer::manage'];
        self::assertSame('refused', $this->ask(['default', 'customer_index_index'], $everything));
        self::assertSame('refused', $this->ask(['default', 'sales_order_index'], $everything));
    }

    public function testOpenLokiPagesAndTheDefaultHandleAreServed(): void
    {
        self::assertSame('allowed', $this->ask(['default', 'loki_admin_components_grid'], []));
    }

    /**
     * @param string[] $handles
     * @param string[] $allowed
     */
    private function ask(array $handles, array $allowed): string
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(fn (string $r): bool => in_array($r, $allowed, true));
        $update = $this->createMock(Merge::class);
        $update->method('getHandles')->willReturn($handles);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getUpdate')->willReturn($update);

        try {
            (new LokiReadGuard(
                $authorization,
                $this->createMock(LoggerInterface::class),
                self::RESOURCES,
                ['loki_admin_components_']
            ))
                ->afterLoad(new \stdClass(), $layout);
            return 'allowed';
        } catch (AuthorizationException $e) {
            return 'refused';
        }
    }
}
