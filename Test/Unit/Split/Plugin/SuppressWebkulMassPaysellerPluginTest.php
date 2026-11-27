<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Plugin;

use RKD\PayuSplit\Split\Plugin\SuppressWebkulMassPaysellerPlugin;
use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Controller\Adminhtml\Order\MassPayseller;

/**
 * A5c-4b — the around-plugin on Webkul's MASS "Pay Seller" controller. It splits the submitted
 * wksellerorderids CSV into saleslist ids and delegates to WebkulPayoutSuppressor, which blocks the
 * whole batch (all-or-nothing) if ANY row is PayU-managed — a partial mass payout would corrupt
 * Webkul's summed payout math.
 */
class SuppressWebkulMassPaysellerPluginTest extends TestCase
{
    /** @var WebkulPayoutSuppressor&MockObject */
    private $suppressor;

    protected function setUp(): void
    {
        $this->suppressor = $this->createMock(WebkulPayoutSuppressor::class);
    }

    private function plugin(): SuppressWebkulMassPaysellerPlugin
    {
        return new SuppressWebkulMassPaysellerPlugin(
            $this->suppressor,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, string> $params
     * @return MassPayseller&MockObject
     */
    private function subject(array $params): MassPayseller
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );
        $subject = $this->createMock(MassPayseller::class);
        $subject->method('getRequest')->willReturn($request);
        return $subject;
    }

    public function testSplitsCsvAndBlocksWhenSuppressorReturnsRedirect(): void
    {
        $redirect = $this->createMock(ResultInterface::class);
        $this->suppressor->expects($this->once())
            ->method('controllerSuppression')
            ->with(18, ['55', '56'])
            ->willReturn($redirect);

        $proceeded = false;
        $proceed = function () use (&$proceeded) {
            $proceeded = true;
            return 'CONTROLLER_RESULT';
        };

        $result = $this->plugin()->aroundExecute(
            $this->subject(['seller_id' => '18', 'wksellerorderids' => '55,56']),
            $proceed
        );

        $this->assertFalse($proceeded, 'A managed row must block the entire mass payout.');
        $this->assertSame($redirect, $result);
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
            $this->subject(['seller_id' => '18', 'wksellerorderids' => '55,56']),
            $proceed
        );

        $this->assertTrue($proceeded);
        $this->assertSame('CONTROLLER_RESULT', $result);
    }

    public function testEmptyCsvResolvesToNoIds(): void
    {
        $this->suppressor->expects($this->once())
            ->method('controllerSuppression')
            ->with(18, [])
            ->willReturn(null);

        $result = $this->plugin()->aroundExecute(
            $this->subject(['seller_id' => '18', 'wksellerorderids' => '']),
            fn () => 'CONTROLLER_RESULT'
        );

        $this->assertSame('CONTROLLER_RESULT', $result);
    }
}
