<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\RefundLeg;
use RKD\PayuSplit\Split\Service\RefundResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * A cancel_refund_transaction response is keyed PER MERCHANT KEY (not flat scalar
 * fields) — proven by sandbox E-12, where one child-keyed var8 produced TWO legs:
 * a child-merchant leg and a parent-commission leg, each with its own request_id.
 * The parser turns the decoded per-key body into one RefundLeg per key so the
 * executor can persist each leg's request_id for status polling.
 */
class RefundResponseParserTest extends TestCase
{
    private RefundResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RefundResponseParser();
    }

    public function testParsesTwoLegsFromE12ShapedResponse(): void
    {
        // The exact shape E-12 returned: child leg + parent commission leg.
        $raw = [
            'CHLD01' => ['status' => 1, 'msg' => 'Refund Request Queued', 'request_id' => '139931269', 'error_code' => 102],
            'PRNT01' => ['status' => 1, 'msg' => 'Refund Request Queued', 'request_id' => '139931270', 'error_code' => 102],
        ];

        $legs = $this->parser->parseLegs($raw);

        $this->assertCount(2, $legs);
        $this->assertSame('CHLD01', $legs[0]->merchantKey);
        $this->assertSame(1, $legs[0]->status);
        $this->assertSame('139931269', $legs[0]->requestId);
        $this->assertSame('102', $legs[0]->errorCode);
        $this->assertTrue($legs[0]->isAccepted());
        $this->assertSame('PRNT01', $legs[1]->merchantKey);
        $this->assertSame('139931270', $legs[1]->requestId);
    }

    public function testAFailureLegIsNotAccepted(): void
    {
        $raw = [
            'CHLD01' => ['status' => 0, 'msg' => 'Refund amount exceeds available', 'error_code' => 'E0805'],
        ];

        $legs = $this->parser->parseLegs($raw);

        $this->assertCount(1, $legs);
        $this->assertFalse($legs[0]->isAccepted());
        $this->assertNull($legs[0]->requestId);
        $this->assertSame('E0805', $legs[0]->errorCode);
        $this->assertStringContainsString('exceeds', $legs[0]->message);
    }

    public function testNumericMerchantKeyLegIsParsedNotDropped(): void
    {
        // A purely-numeric PayU child merchant key: json_decode(..., true) coerces the object
        // key to an int, so parseLegs must still capture the leg (string-coerced). Dropping it
        // would leave the debited child segment's refunded_paise unbumped while
        // RefundExecutor::findResponseAnomalies (which (string)-casts the key) treats the same
        // response as expected — the F4 asymmetry.
        $raw = [
            94663 => ['status' => 1, 'msg' => 'Refund Request Queued', 'request_id' => '77', 'error_code' => 102],
            'PRNT01' => ['status' => 1, 'request_id' => '78', 'msg' => 'Queued'],
        ];

        $legs = $this->parser->parseLegs($raw);

        $this->assertCount(2, $legs);
        $this->assertSame('94663', $legs[0]->merchantKey);
        $this->assertSame('77', $legs[0]->requestId);
        $this->assertTrue($legs[0]->isAccepted());
        $this->assertSame('PRNT01', $legs[1]->merchantKey);
    }

    public function testNonArrayBodyYieldsNoLegs(): void
    {
        $this->assertSame([], $this->parser->parseLegs('garbage'));
        $this->assertSame([], $this->parser->parseLegs(null));
    }

    public function testIgnoresNonLegEntriesDefensively(): void
    {
        // A stray scalar at the top level (e.g. a global 'status') is not a merchant leg.
        $raw = [
            'status' => 1,
            'CHLD01' => ['status' => 1, 'request_id' => '42', 'msg' => 'Queued'],
        ];

        $legs = $this->parser->parseLegs($raw);

        $this->assertCount(1, $legs);
        $this->assertSame('CHLD01', $legs[0]->merchantKey);
    }
}
