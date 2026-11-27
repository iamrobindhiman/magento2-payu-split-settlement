<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Diagnostic\Observer;

use RKD\PayuSplit\Diagnostic\Observer\ResponsePostdispatch;
use RKD\PayuSplit\Model\Config;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * If the predispatch observer logs entry but THIS one never logs exit, that's our
 * signal of a die-trap (Response.php:137 calls die() inside catch — anything between
 * predispatch and postdispatch never returns).
 */
class ResponsePostdispatchTest extends TestCase
{
    /** @var Config&MockObject */
    private Config $config;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var HttpResponse&MockObject */
    private HttpResponse $response;
    private ResponsePostdispatch $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->response = $this->createMock(HttpResponse::class);

        $this->observer = new ResponsePostdispatch(
            $this->config,
            $this->logger,
            $this->response
        );
    }

    public function testShortCircuitsWhenLoggingDisabled(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method('info');

        $this->observer->execute(new EventObserver(['event' => new Event()]));
    }

    public function testLogsCompletionWithFinalRedirectUrlAndStatusCode(): void
    {
        $this->config->method('isLoggingEnabled')->willReturn(true);
        $this->response->method('getHttpResponseCode')->willReturn(302);
        $header = new class {
            public function getFieldValue(): string
            {
                return 'https://example.com/checkout/onepage/success';
            }
        };
        $this->response->method('getHeader')->willReturnCallback(
            fn ($name) => $name === 'Location' ? $header : false
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('payu response controller exit'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(302, $context['http_status']);
                    $this->assertStringContainsString('checkout/onepage/success', $context['redirect_to']);
                    return true;
                })
            );

        $this->observer->execute(new EventObserver(['event' => new Event()]));
    }
}
