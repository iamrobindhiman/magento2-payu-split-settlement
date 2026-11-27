<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Diagnostic\Plugin;

use RKD\PayuSplit\Diagnostic\Plugin\VerifyPaymentPlugin;
use RKD\PayuSplit\Model\Config;
use Magento\Sales\Model\Order;
use PayUIndia\Payu\Model\Payu;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Hook 4 — wraps PayUIndia\Payu\Model\Payu::verifyPayment.
 * verifyPayment is the cURL roundtrip to PayU's verify_payment endpoint that
 * confirms the response we received matches what PayU has on record.
 */
class VerifyPaymentPluginTest extends TestCase
{
    /** @var Config&MockObject */
    private Config $config;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var Payu&MockObject */
    private Payu $subject;
    /** @var Order&MockObject */
    private Order $order;
    private VerifyPaymentPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(Payu::class);
        $this->order = $this->createMock(Order::class);

        $this->plugin = new VerifyPaymentPlugin($this->config, $this->logger);
    }

    public function testShortCircuitsWhenLoggingDisabled(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method($this->anything());

        $proceed = fn() => true;
        $this->plugin->aroundVerifyPayment($this->subject, $proceed, $this->order, 'T1');
    }

    public function testLogsResultWithTxnidAndDuration(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $proceed = fn() => true;

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('verifyPayment'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('000000042', $context['order_increment_id']);
                    $this->assertSame('T1', $context['txnid']);
                    $this->assertTrue($context['result']);
                    $this->assertArrayHasKey('duration_ms', $context);
                    return true;
                })
            );

        $this->plugin->aroundVerifyPayment($this->subject, $proceed, $this->order, 'T1');
    }

    public function testLogsExceptionAndRethrowsOnCurlError(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->order->method('getIncrementId')->willReturn('000000042');

        $proceed = function () {
            throw new \RuntimeException('cURL: connection timeout');
        };

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('verifyPayment threw'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('T1', $context['txnid']);
                    $this->assertSame(\RuntimeException::class, $context['exception_class']);
                    return true;
                })
            );

        $this->expectException(\RuntimeException::class);

        $this->plugin->aroundVerifyPayment($this->subject, $proceed, $this->order, 'T1');
    }
}
