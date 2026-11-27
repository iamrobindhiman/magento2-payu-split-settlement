<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Exception\OnboardingPayloadException;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Service\OnboardingPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The payload builder is where the irreversible mistakes are stopped, so most of
 * these tests are about what it REFUSES to build. Two things cannot be undone once
 * PayU has answered: an orphaned merchant, and a spent bank-detail attempt.
 */
class OnboardingPayloadBuilderTest extends TestCase
{
    /**
     * @param string $parentMid
     * @return OnboardingPayloadBuilder
     */
    private function builder(string $parentMid = '9000100'): OnboardingPayloadBuilder
    {
        $config = $this->createMock(OnboardingConfig::class);
        $config->method('getParentMid')->willReturn($parentMid);
        return new OnboardingPayloadBuilder($config);
    }

    /**
     * @return ChildMerchantDraft
     */
    private function draft(): ChildMerchantDraft
    {
        return new ChildMerchantDraft(
            'Ortho Supplies',
            'vendor@example.invalid',
            '9000000000',
            'Ortho Supplies Pvt Ltd',
            2,
            'AAAPA0000A',
            'Ortho Supplies',
            60000
        );
    }

    public function testCreatePayloadCarriesTheParentMidAndTheDocumentedMerchantType(): void
    {
        $payload = $this->builder()->buildCreate($this->draft());
        $account = $payload['product_account'];

        self::assertSame('9000100', $account['aggregator_parent_mid']);
        // Posted as "aggregator"; PayU echoes back "child_aggregator" once resolved.
        self::assertSame('aggregator', $account['merchant_type']);
        self::assertSame('PayUbiz', $account['product']);
    }

    /**
     * T-04. Without the parent MID PayU answers 200 and creates an account outside
     * the aggregator that can never be read, edited, split to or deleted. This has
     * to fail on our side of the wire.
     */
    public function testCreateIsRefusedWithoutAParentMid(): void
    {
        $this->expectException(OnboardingPayloadException::class);
        $this->expectExceptionMessageMatches('/orphan/i');

        $this->builder('')->buildCreate($this->draft());
    }

    public function testCreateIsRefusedWithNeitherEmailNorMobile(): void
    {
        $this->expectException(OnboardingPayloadException::class);

        $this->builder()->buildCreate(
            new ChildMerchantDraft('Ortho', '', '', 'Ortho Pvt Ltd', 2)
        );
    }

    public function testCreateIsRefusedWithoutABusinessEntityType(): void
    {
        $this->expectException(OnboardingPayloadException::class);

        $this->builder()->buildCreate(
            new ChildMerchantDraft('Ortho', 'v@example.invalid', '9000000000', 'Ortho Pvt Ltd', 0)
        );
    }

    public function testPanIsOmittedRatherThanSentEmpty(): void
    {
        $payload = $this->builder()->buildCreate(
            new ChildMerchantDraft('Ortho', 'v@example.invalid', '9000000000', 'Ortho Pvt Ltd', 2)
        );

        self::assertArrayNotHasKey('pancard_number', $payload['product_account']);
        self::assertArrayNotHasKey('pancard_name', $payload['product_account']);
    }

    public function testBankPayloadIsShapedForPayuAndUppercasesTheIfsc(): void
    {
        $payload = $this->builder()->buildBankDetail('50100000000000', 'hdfc0000000', 'Ortho Supplies');
        $bank = $payload['product_account']['bank_detail'];

        self::assertSame('50100000000000', $bank['bank_account_number']);
        self::assertSame('HDFC0000000', $bank['ifsc_code']);
        self::assertSame('Ortho Supplies', $bank['holder_name']);
    }

    /**
     * N-4: PayU accepts the settlement account ONCE and then refuses. A malformed
     * value must never be the thing that spends the attempt.
     *
     * @dataProvider unsendableBankDetails
     * @param string $account
     * @param string $ifsc
     * @param string $holder
     */
    public function testBankDetailsThatWouldWasteTheSingleAttemptAreRefused(
        string $account,
        string $ifsc,
        string $holder
    ): void {
        $this->expectException(OnboardingPayloadException::class);

        $this->builder()->buildBankDetail($account, $ifsc, $holder);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unsendableBankDetails(): array
    {
        return [
            'account too short' => ['12345678', 'HDFC0000000', 'Ortho'],
            'account too long' => ['1234567890123456789', 'HDFC0000000', 'Ortho'],
            'account not numeric' => ['5010A388217465', 'HDFC0000000', 'Ortho'],
            'account empty' => ['', 'HDFC0000000', 'Ortho'],
            'ifsc missing the fifth-character zero' => ['50100000000000', 'HDFC1001234', 'Ortho'],
            'ifsc too short' => ['50100000000000', 'HDFC000123', 'Ortho'],
            'ifsc empty' => ['50100000000000', '', 'Ortho'],
            'holder empty' => ['50100000000000', 'HDFC0000000', '   '],
        ];
    }
}
