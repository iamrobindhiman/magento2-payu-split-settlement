<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Plugin;

use RKD\PayuSplit\Split\Plugin\SuppressWebkulPaysellerPlugin;
use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Controller\Adminhtml\Order\Payseller;

/**
 * A5c-4b — the around-plugin on Webkul's single "Pay Seller" controller. It extracts the controller's
 * one saleslist id (autoorderid) + seller, delegates the INV-17 decision to WebkulPayoutSuppressor,
 * and short-circuits with the suppressor's redirect (never calling proceed) when the payout is
 * managed; otherwise it proceeds.
 */
class SuppressWebkulPaysellerPluginTest extends TestCase
{
    /** @var WebkulPayoutSuppressor&MockObject */
    private $suppressor;

    protected function setUp(): void
    {
        $this->suppressor = $this->createMock(WebkulPayoutSuppressor::class);
    }

    private function plugin(): SuppressWebkulPaysellerPlugin
    {
        return new SuppressWebkulPaysellerPlugin(
            $this->suppressor,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, string> $params
     * @return Payseller&MockObject
     */
    private function subject(array $params): Payseller
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );
        $subject = $this->createMock(Payseller::class);
        $subject->method('getRequest')->willReturn($request);
        return $subject;
    }

    public function testProceedsWhenSuppressorAllows(): void
    {
        $this->suppressor->method('controllerSuppression')->willReturn(null);

        $proceeded = false;
        $proceed = function () use (&$proceeded) {
            $proceeded = true;
            return 'CONTROLLER_RESULT';
        };

        $result = $this->plugin()->aroundExecute(
            $this->subject(['seller_id' => '18', 'autoorderid' => '55']),
            $proceed
        );

        $this->assertTrue($proceeded);
        $this->assertSame('CONTROLLER_RESULT', $result);
    }

    public function testBlocksWithoutProceedingWhenSuppressorReturnsRedirect(): void
    {
        $redirect = $this->createMock(ResultInterface::class);
        $this->suppressor->expects($this->once())
            ->method('controllerSuppression')
            ->with(18, [55])
            ->willReturn($redirect);

        $proceeded = false;
        $proceed = function () use (&$proceeded) {
            $proceeded = true;
            return 'CONTROLLER_RESULT';
        };

        $result = $this->plugin()->aroundExecute(
            $this->subject(['seller_id' => '18', 'autoorderid' => '55']),
            $proceed
        );

        $this->assertFalse($proceeded, 'The native payout must NOT run when the suppressor blocks.');
        $this->assertSame($redirect, $result);
    }
}
