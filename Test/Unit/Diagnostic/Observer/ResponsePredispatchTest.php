<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Diagnostic\Observer;

use RKD\PayuSplit\Diagnostic\Observer\ResponsePredispatch;
use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use RKD\PayuSplit\Model\Config;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResponsePredispatchTest extends TestCase
{
    /** @var Config&MockObject */
    private Config $config;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var HttpRequest&MockObject */
    private HttpRequest $request;
    private ResponsePredispatch $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->request = $this->createMock(HttpRequest::class);

        $this->observer = new ResponsePredispatch(
            $this->config,
            new PayloadRedactor(false),
            $this->logger,
            $this->request
        );
    }

    private function makeEvent(): EventObserver
    {
        return new EventObserver(['event' => new Event()]);
    }

    public function testShortCircuitsWhenLoggingDisabled(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method('info');

        $this->observer->execute($this->makeEvent());
    }

    public function testLogsFullResponsePayloadAtInfoOnEntry(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->request->method('getParams')->willReturn([
            'txnid' => 'T123',
            'status' => 'success',
            'mihpayid' => 'MIH456',
            'firstname' => 'Robin',
            'email' => 'robin@example.com',
            'hash' => str_repeat('h', 128),
            'udf1' => 'sess123',
            'udf5' => 'Magento_v.2.4.6',
        ]);
        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getClientIp')->willReturn('203.0.113.5');
        $this->request->method('getServer')->willReturn('TestAgent/1.0');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('payu response controller entry'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('T123', $context['payload']['txnid']);
                    $this->assertSame('success', $context['payload']['status']);
                    $this->assertStringContainsString('hhhhhhhh...len=128', $context['payload']['hash']);
                    $this->assertSame('POST', $context['method']);
                    $this->assertSame('203.0.113.5', $context['client_ip']);
                    return true;
                })
            );

        $this->observer->execute($this->makeEvent());
    }

    public function testFlattensFullResponseJsonFromBoltMode(): void
    {
        // Bolt mode wraps the entire response in a `full_response` JSON string.
        // The observer must decode it so we see real fields, not `{"full_response": "..."}`.
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $bolt = json_encode(['txnid' => 'BOLT1', 'status' => 'success', 'mihpayid' => 'M9']);
        $this->request->method('getParams')->willReturn(['full_response' => $bolt]);
        $this->request->method('getMethod')->willReturn('POST');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(function (array $context): bool {
                    $this->assertSame('BOLT1', $context['payload']['txnid']);
                    $this->assertSame('M9', $context['payload']['mihpayid']);
                    $this->assertTrue($context['bolt_mode']);
                    return true;
                })
            );

        $this->observer->execute($this->makeEvent());
    }
}
