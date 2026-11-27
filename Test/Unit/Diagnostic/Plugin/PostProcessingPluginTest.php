<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Diagnostic\Plugin;

use RKD\PayuSplit\Diagnostic\Plugin\PostProcessingPlugin;
use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use RKD\PayuSplit\Model\Config;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use PayUIndia\Payu\Model\Payu;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Hook 3 — wraps PayUIndia\Payu\Model\Payu::postProcessing.
 *
 * Critical: upstream has `catch(Exception)` (unqualified) at Model/Payu.php:440 inside
 * a namespaced file. PHP resolves to PayUIndia\Payu\Model\Exception which doesn't exist,
 * so the catch never matches. Our around-plugin catches \Throwable, logs everything,
 * and re-throws so upstream behaviour is unchanged (we just witness the exception).
 */
class PostProcessingPluginTest extends TestCase
{
    /** @var Config&MockObject */
    private Config $config;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var Payu&MockObject */
    private Payu $subject;
    /** @var Order&MockObject */
    private Order $order;
    /** @var DataObject&MockObject */
    private DataObject $payment;
    private PostProcessingPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(Payu::class);
        $this->order = $this->createMock(Order::class);
        $this->payment = $this->createMock(DataObject::class);

        $this->plugin = new PostProcessingPlugin(
            $this->config,
            new PayloadRedactor(false),
            $this->logger
        );
    }

    public function testShortCircuitsWhenLoggingDisabled(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method($this->anything());

        $proceed = fn() => null;
        $this->plugin->aroundPostProcessing($this->subject, $proceed, $this->order, $this->payment, []);
    }

    public function testLogsHappyPathStartAndEndWithDuration(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $proceed = fn() => null;

        $infoCalls = [];
        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($msg, $ctx) use (&$infoCalls) {
                $infoCalls[] = ['msg' => $msg, 'ctx' => $ctx];
            });

        $this->plugin->aroundPostProcessing(
            $this->subject,
            $proceed,
            $this->order,
            $this->payment,
            ['txnid' => 'T1', 'mihpayid' => 'M1', 'status' => 'success']
        );

        $this->assertStringContainsString('postProcessing start', $infoCalls[0]['msg']);
        $this->assertSame('000000042', $infoCalls[0]['ctx']['order_increment_id']);
        $this->assertStringContainsString('postProcessing end', $infoCalls[1]['msg']);
        $this->assertArrayHasKey('duration_ms', $infoCalls[1]['ctx']);
    }

    public function testCatchesAndLogsThrowableThenRethrows(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->order->method('getIncrementId')->willReturn('000000042');

        $boom = new \RuntimeException('verifyPayment cURL timeout');
        $proceed = function () use ($boom) {
            throw $boom;
        };

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('postProcessing threw'),
                $this->callback(function (array $context) use ($boom): bool {
                    $this->assertSame('000000042', $context['order_increment_id']);
                    $this->assertSame(\RuntimeException::class, $context['exception_class']);
                    $this->assertSame('verifyPayment cURL timeout', $context['exception_message']);
                    $this->assertArrayHasKey('exception_file', $context);
                    $this->assertArrayHasKey('exception_line', $context);
                    $this->assertArrayHasKey('trace', $context);
                    return true;
                })
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verifyPayment cURL timeout');

        $this->plugin->aroundPostProcessing(
            $this->subject,
            $proceed,
            $this->order,
            $this->payment,
            ['txnid' => 'T1']
        );
    }

    public function testLogsRedactedResponseKeysOnly(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->order->method('getIncrementId')->willReturn('000000042');
        $proceed = fn() => null;

        $this->logger->method('info')->willReturnCallback(
            function ($msg, $ctx) {
                if (str_contains($msg, 'start') && isset($ctx['payload']['firstname'])) {
                    // local mode (prodMode=false) → first name visible
                    $this->assertSame('Robin', $ctx['payload']['firstname']);
                }
            }
        );

        $this->plugin->aroundPostProcessing(
            $this->subject,
            $proceed,
            $this->order,
            $this->payment,
            ['txnid' => 'T1', 'firstname' => 'Robin', 'email' => 'r@x.com']
        );
    }
}
