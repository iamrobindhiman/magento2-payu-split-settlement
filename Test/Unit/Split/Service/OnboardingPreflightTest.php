<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\SettlementBankDetail;
use RKD\PayuSplit\Split\Service\BusinessEntityResolver;
use RKD\PayuSplit\Split\Service\OnboardingPreflight;
use RKD\PayuSplit\Split\Service\VendorOnboardingDataReader;
use PHPUnit\Framework\TestCase;

/**
 * What must be caught before PayU is called, and the mapping of legal types onto PayU's
 * entity ids. A child merchant cannot be deleted, so these checks are the last chance.
 */
class OnboardingPreflightTest extends TestCase
{
    private OnboardingPreflight $preflight;
    private BusinessEntityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new BusinessEntityResolver();
        $this->preflight = new OnboardingPreflight($this->resolver);
    }

    public function testACompleteProprietorPasses(): void
    {
        self::assertSame([], $this->preflight->check($this->draft(), 'proprietorship', $this->bank()));
    }

    public function testEveryLegalTypeTheFormOffersMapsToAPayuEntity(): void
    {
        $expected = [
            'proprietorship' => 2, 'partnership' => 3, 'llp' => 6, 'pvt_ltd' => 4,
            'public_ltd' => 5, 'trust' => 7, 'society' => 8,
        ];
        foreach ($expected as $legalType => $entityId) {
            self::assertSame($entityId, $this->resolver->entityId($legalType), $legalType);
        }
        self::assertNull($this->resolver->entityId(null));
        self::assertNull($this->resolver->entityId('individual'));
    }

    public function testThePanCategoryLetterMustFitTheLegalType(): void
    {
        self::assertTrue($this->resolver->panFitsLegalType('AAACA0000A', 'pvt_ltd'));
        self::assertTrue($this->resolver->panFitsLegalType('AAAFA0000A', 'llp'));
        self::assertTrue($this->resolver->panFitsLegalType('AAATA0000A', 'society'));
        self::assertFalse($this->resolver->panFitsLegalType('AAAPA0000A', 'pvt_ltd'));
        self::assertFalse($this->resolver->panFitsLegalType('SHORT', 'proprietorship'));

        $problems = $this->preflight->check($this->draft('AAACA0000A'), 'proprietorship', $this->bank());
        self::assertCount(1, $problems);
        self::assertStringContainsString('expected P', $problems[0]);
    }

    public function testAMissingLegalTypeIsAProblem(): void
    {
        $problems = $this->preflight->check($this->draft(), null, $this->bank());
        self::assertNotEmpty(array_filter($problems, static fn (string $p): bool => str_contains($p, 'Legal type')));
    }

    public function testAnUnreadablePanIsAProblem(): void
    {
        // SensitiveValueCodec returns '' for a ciphertext it cannot read.
        $problems = $this->preflight->check($this->draft(''), 'proprietorship', $this->bank());
        self::assertStringContainsString('PAN is missing', implode(' ', $problems));
    }

    public function testMobileAndEmailMustBeUsable(): void
    {
        $draft = new ChildMerchantDraft('A', 'not-an-email', '12345', 'B', 2, 'AAAPA0000A', 'A', 1);
        $problems = implode(' ', $this->preflight->check($draft, 'proprietorship', $this->bank()));
        self::assertStringContainsString('Email', $problems);
        self::assertStringContainsString('Mobile', $problems);
    }

    public function testBankProblemsAreCaughtAndNeverEchoTheValue(): void
    {
        $problems = $this->preflight->checkBank(new SettlementBankDetail('12AB', 'HDFC1234', ''));
        self::assertCount(3, $problems);
        self::assertStringNotContainsString('12AB', implode(' ', $problems));
    }

    public function testTheBankDetailNeverPrintsTheAccountNumber(): void
    {
        $dump = print_r(new SettlementBankDetail('50100000000002', 'HDFC0000000', 'X'), true);
        self::assertStringNotContainsString('50100000000002', $dump);
        self::assertStringContainsString('0002', $dump);
    }

    public function testTheStoredMobileLosesItsCountryCode(): void
    {
        self::assertSame('9000000002', VendorOnboardingDataReader::normaliseMobile('919000000002'));
        self::assertSame('9000000002', VendorOnboardingDataReader::normaliseMobile('+91 90000 00002'));
        self::assertSame('9000000002', VendorOnboardingDataReader::normaliseMobile('09000000002'));
        self::assertSame('9000000003', VendorOnboardingDataReader::normaliseMobile('9000000003'));
    }

    private function draft(string $pan = 'AAAPA0000A'): ChildMerchantDraft
    {
        return new ChildMerchantDraft(
            'Test Vendor',
            'vendor@example.invalid',
            '9000000001',
            'Test Ortho Supplies',
            2,
            $pan,
            'Test Vendor',
            60000
        );
    }

    private function bank(): SettlementBankDetail
    {
        return new SettlementBankDetail('50100000000000', 'HDFC0000000', 'Test Vendor');
    }
}
