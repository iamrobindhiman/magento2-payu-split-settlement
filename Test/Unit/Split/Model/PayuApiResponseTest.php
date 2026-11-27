<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\PayuApiResponse;
use PHPUnit\Framework\TestCase;

/**
 * The response value object is what the settlement logic branches on. status 1/2/0
 * and the AGG-* error codes drive idempotency and retry decisions, so their mapping
 * is pinned here.
 */
class PayuApiResponseTest extends TestCase
{
    public function testSuccessStatus(): void
    {
        $response = PayuApiResponse::fromFields(
            ['status' => '1', 'message' => 'Splits creation successful.'],
            'json',
            []
        );
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isFailure());
        $this->assertSame('Splits creation successful.', $response->message());
    }

    public function testPendingStatus(): void
    {
        $response = PayuApiResponse::fromFields(
            ['status' => '2', 'message' => 'Splits saved, but not created yet'],
            'json',
            []
        );
        $this->assertTrue($response->isPending());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isFailure());
    }

    public function testAlreadySplitIsTheIdempotencySignal(): void
    {
        $response = PayuApiResponse::fromFields(
            ['status' => '0', 'error_code' => 'AGG-104', 'error_desc' => 'Split already exists'],
            'json',
            []
        );
        $this->assertTrue($response->isFailure());
        $this->assertTrue($response->isAlreadySplit());
        $this->assertFalse($response->isTransientLock());
        $this->assertSame('AGG-104', $response->errorCode());
        $this->assertSame('Split already exists', $response->message());
    }

    public function testTransientLockIsAgg110(): void
    {
        $response = PayuApiResponse::fromFields(['status' => '0', 'error_code' => 'AGG-110'], 'json', []);
        $this->assertTrue($response->isTransientLock());
        $this->assertFalse($response->isAlreadySplit());
    }

    public function testMessageFallsBackAcrossPayuFieldNames(): void
    {
        $this->assertSame('m', PayuApiResponse::fromFields(['msg' => 'm'], 'json', [])->message());
        $this->assertSame('d', PayuApiResponse::fromFields(['error_desc' => 'd', 'msg' => 'm'], 'json', [])->message());
        $this->assertSame('', PayuApiResponse::fromFields([], 'json', [])->message());
    }

    public function testFieldStatusFormatAndRawAccessors(): void
    {
        $response = PayuApiResponse::fromFields(['payuId' => '123', 'status' => '1'], 'print_r', 'rawbody');
        $this->assertSame('123', $response->field('payuId'));
        $this->assertNull($response->field('missing'));
        $this->assertSame('1', $response->status());
        $this->assertSame('print_r', $response->format());
        $this->assertSame('rawbody', $response->raw());
    }

    public function testNoStatusYieldsNoSuccessPendingOrFailure(): void
    {
        $response = PayuApiResponse::fromFields([], 'unknown', '');
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isPending());
        $this->assertFalse($response->isFailure());
        $this->assertNull($response->status());
    }

    public function testIsAlreadyReleasedDetectsReleaseIdempotency(): void
    {
        $already = PayuApiResponse::fromFields(
            ['status' => '0', 'msg' => 'Release request is already accepted'],
            'json',
            []
        );
        $this->assertTrue($already->isAlreadyReleased());

        $fresh = PayuApiResponse::fromFields(
            ['status' => '1', 'msg' => 'Release request is accepted'],
            'json',
            []
        );
        $this->assertFalse($fresh->isAlreadyReleased());
    }
}
