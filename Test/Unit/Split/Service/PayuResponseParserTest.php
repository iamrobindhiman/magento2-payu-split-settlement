<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Service\PayuResponseParser;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * PayU returns JSON for some commands and print_r-in-<pre> for others (get_split_info
 * on UAT). The parser must read both into the same PayuApiResponse interface, and
 * degrade gracefully on garbage (a gateway 503 HTML page must not crash settlement).
 */
class PayuResponseParserTest extends TestCase
{
    private PayuResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PayuResponseParser(new Json());
    }

    public function testParsesJsonPaymentSplitSuccess(): void
    {
        $body = '{"status":1,"message":"Splits creation successful.","splitStatus":"success"}';
        $response = $this->parser->parse($body);

        $this->assertSame('json', $response->format());
        $this->assertTrue($response->isSuccess());
        $this->assertSame('1', $response->status());
        $this->assertSame('success', $response->field('splitStatus'));
    }

    public function testParsesJsonFailureWithAgg104(): void
    {
        $body = '{"status":0,"error_code":"AGG-104","error_desc":"Split already exists for this transaction"}';
        $response = $this->parser->parse($body);

        $this->assertSame('json', $response->format());
        $this->assertTrue($response->isAlreadySplit());
        $this->assertSame('AGG-104', $response->errorCode());
    }

    public function testParsesPrintRGetSplitInfo(): void
    {
        // UAT get_split_info returns print_r wrapped in <pre> (experiment E-01).
        $body = "<pre>Array\n(\n    [status] => 1\n    [payuId] => 100000000000000002\n"
            . "    [splitStatus] => success\n)\n</pre>";
        $response = $this->parser->parse($body);

        $this->assertSame('print_r', $response->format());
        $this->assertTrue($response->isSuccess());
        $this->assertSame('100000000000000002', $response->field('payuId'));
        $this->assertSame('success', $response->field('splitStatus'));
    }

    public function testUnknownBodyYieldsEmptyFieldsAndDoesNotCrash(): void
    {
        $response = $this->parser->parse('<html><body>503 Service Unavailable</body></html>');
        $this->assertSame('unknown', $response->format());
        $this->assertNull($response->status());
        $this->assertFalse($response->isSuccess());
    }

    public function testMalformedJsonFallsThroughGracefully(): void
    {
        // Truncated JSON must not throw — settlement treats it as unknown and retries later.
        $response = $this->parser->parse('{"status":1,');
        $this->assertSame('unknown', $response->format());
        $this->assertNull($response->status());
    }

    public function testEmptyBodyIsUnknown(): void
    {
        $response = $this->parser->parse('');
        $this->assertSame('unknown', $response->format());
    }
}
