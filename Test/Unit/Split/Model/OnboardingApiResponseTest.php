<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use PHPUnit\Framework\TestCase;

/**
 * PayU's onboarding API reports failure in three different shapes, all observed on
 * UAT within one session. A caller that only understood one of them would store
 * "PayU returned HTTP 422 with no error detail" for the other two, and staff working
 * the queue would have nothing to act on.
 */
class OnboardingApiResponseTest extends TestCase
{
    public function testTheNestedErrorsShapeIsFlattened(): void
    {
        $response = new OnboardingApiResponse(
            422,
            ['errors' => ['bank_details' => ['Bank details already submitted']]],
            '{}'
        );

        self::assertSame(['Bank details already submitted'], $response->errorMessages());
    }

    public function testTheSingleErrorStringShapeIsRead(): void
    {
        $response = new OnboardingApiResponse(
            422,
            ['error' => 'param is missing or the value is empty: product_account'],
            '{}'
        );

        self::assertSame(
            ['param is missing or the value is empty: product_account'],
            $response->errorMessages()
        );
    }

    public function testTheBareStatusShapeIsRead(): void
    {
        $response = new OnboardingApiResponse(401, ['status' => 'Unauthorized'], '{}');

        self::assertSame(['Unauthorized'], $response->errorMessages());
        self::assertTrue($response->isUnauthorized());
    }

    public function testASuccessfulResponseWithAStatusFieldIsNotReadAsAnError(): void
    {
        // settlement_status "Active" lives on a 200. "status" must not be mined for
        // an error message when the call actually succeeded.
        $response = new OnboardingApiResponse(200, ['status' => 'Active'], '{}');

        self::assertTrue($response->isSuccess());
        self::assertSame([], $response->errorMessages());
    }

    public function testMultipleFieldErrorsAreAllReported(): void
    {
        $response = new OnboardingApiResponse(422, ['errors' => [
            'bank_details' => ['Bank details already submitted'],
            'mobile' => ['is invalid', 'is already taken'],
        ]], '{}');

        self::assertSame(
            ['Bank details already submitted', 'is invalid', 'is already taken'],
            $response->errorMessages()
        );
    }

    public function testErrorSummaryIsOneStorableLine(): void
    {
        $response = new OnboardingApiResponse(
            422,
            ['errors' => ['bank_details' => ['Bank details already submitted']]],
            '{}'
        );

        self::assertSame('HTTP 422: Bank details already submitted', $response->errorSummary());
    }

    public function testErrorSummarySaysSoWhenPayuGaveNoDetail(): void
    {
        $response = new OnboardingApiResponse(500, [], '');

        self::assertSame('PayU returned HTTP 500 with no error detail.', $response->errorSummary());
    }

    public function testProductAccountIsNullWhenTheResponseCarriedNone(): void
    {
        self::assertNull((new OnboardingApiResponse(422, ['errors' => []], '{}'))->productAccount());
    }
}
