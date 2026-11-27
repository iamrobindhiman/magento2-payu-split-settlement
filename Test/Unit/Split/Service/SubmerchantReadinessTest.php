<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\SubmerchantReadiness;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The readiness truth table.
 *
 * The case that matters most is testCreatedButPendingKycIsNotReady: PayU returns
 * is_active alongside a PENDING kyc_status on a brand-new sub-merchant, so any rule
 * that trusts is_active alone will happily route real money at an account PayU has
 * not activated. That is defect D-2, and this test is its regression guard.
 */
class SubmerchantReadinessTest extends TestCase
{
    /** @var SubmerchantRepositoryInterface&MockObject */
    private $repository;

    private SubmerchantReadiness $readiness;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $this->readiness = new SubmerchantReadiness($this->repository);
    }

    private function row(array $data): Submerchant
    {
        $model = $this->getMockBuilder(Submerchant::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }
        return $model;
    }

    private function given(?Submerchant $row): void
    {
        $this->repository->method('getBySellerId')->willReturn($row);
    }

    public function testNoRowMeansNotReady(): void
    {
        $this->given(null);
        $this->assertFalse($this->readiness->canSell(7));
        $this->assertSame('No PayU sub-merchant record exists for this vendor yet.', $this->readiness->explain(7));
    }

    public function testFullyActivatedVendorIsReady(): void
    {
        $this->given($this->row([
            'onboarding_state' => Submerchant::STATE_ACTIVE,
            'is_active' => 1,
            'payu_merchant_key' => 'CHLD04',
        ]));
        $this->assertTrue($this->readiness->canSell(7));
        $this->assertNull($this->readiness->explain(7));
    }

    /**
     * Defect D-2. A sub-merchant PayU has only just created reports is_active while its
     * KYC is still pending — existence and is_active must never be read as readiness.
     */
    public function testCreatedButPendingKycIsNotReady(): void
    {
        $this->given($this->row([
            'onboarding_state' => Submerchant::STATE_CREATED,
            'is_active' => 1,
            'kyc_status' => Submerchant::KYC_PENDING,
            'payu_merchant_key' => 'CHLD04',
        ]));
        $this->assertFalse($this->readiness->canSell(7));
    }

    public function testActiveStateWithoutAMerchantKeyIsNotReady(): void
    {
        $this->given($this->row([
            'onboarding_state' => Submerchant::STATE_ACTIVE,
            'is_active' => 1,
        ]));
        $this->assertFalse($this->readiness->canSell(7));
    }

    public function testActiveStateButDeactivatedRowIsNotReady(): void
    {
        $this->given($this->row([
            'onboarding_state' => Submerchant::STATE_ACTIVE,
            'is_active' => 0,
            'payu_merchant_key' => 'CHLD04',
        ]));
        $this->assertFalse($this->readiness->canSell(7));
    }

    /**
     * @dataProvider unreadyStateProvider
     */
    public function testEveryNonActiveStateIsUnready(string $state): void
    {
        $this->given($this->row([
            'onboarding_state' => $state,
            'is_active' => 1,
            'payu_merchant_key' => 'CHLD04',
        ]));
        $this->assertFalse($this->readiness->canSell(7), sprintf('%s must not be sellable', $state));
        $this->assertNotNull($this->readiness->explain(7), sprintf('%s must explain itself', $state));
    }

    /** @return array<string, string[]> */
    public static function unreadyStateProvider(): array
    {
        return [
            'not started' => [Submerchant::STATE_NOT_STARTED],
            'queued' => [Submerchant::STATE_QUEUED],
            'created' => [Submerchant::STATE_CREATED],
            'bank attached' => [Submerchant::STATE_BANK_ATTACHED],
            'awaiting esign' => [Submerchant::STATE_AWAITING_ESIGN],
            'failed' => [Submerchant::STATE_FAILED],
        ];
    }

    public function testEsignExplanationDistinguishesSentFromNotSent(): void
    {
        $this->given($this->row([
            'onboarding_state' => Submerchant::STATE_AWAITING_ESIGN,
            'is_active' => 1,
            'payu_merchant_key' => 'CHLD04',
            'esign_sent_at' => '2026-09-10 11:00:00',
        ]));
        $this->assertStringContainsString('waiting for the vendor to sign', (string) $this->readiness->explain(7));
    }

    public function testFailureExplanationCarriesTheReason(): void
    {
        $this->given($this->row([
            'onboarding_state' => Submerchant::STATE_FAILED,
            'last_error' => 'PAN verification rejected',
        ]));
        $this->assertStringContainsString('PAN verification rejected', (string) $this->readiness->explain(7));
    }
}
