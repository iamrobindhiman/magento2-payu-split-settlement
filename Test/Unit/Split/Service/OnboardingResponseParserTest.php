<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ChildMerchant;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Service\OnboardingResponseParser;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * Parses responses RECORDED FROM PAYU UAT, not hand-written to match the parser.
 * If PayU changes a shape, re-record the fixture and these fail — which is the point.
 */
class OnboardingResponseParserTest extends TestCase
{
    private OnboardingResponseParser $parser;
    private Json $json;

    protected function setUp(): void
    {
        $this->parser = new OnboardingResponseParser();
        $this->json = new Json();
    }

    /**
     * @param string $name
     * @return OnboardingApiResponse
     */
    private function fixture(string $name): OnboardingApiResponse
    {
        $path = __DIR__ . '/../_fixtures/onboarding/' . $name . '.json';
        self::assertFileExists($path, 'Missing recorded fixture: ' . $name);

        /** @var array<string, mixed> $recorded */
        $recorded = $this->json->unserialize((string)file_get_contents($path));
        $body = is_array($recorded['body'] ?? null) ? $recorded['body'] : [];

        return new OnboardingApiResponse((int)$recorded['http'], $body, $this->json->serialize($body));
    }

    public function testReadsIdentifiersOutOfTheRealCreateResponse(): void
    {
        $child = $this->parser->parseChildMerchant($this->fixture('02-create-child'));

        self::assertInstanceOf(ChildMerchant::class, $child);
        // Not merchant.uuid — the lab harness looked there and found nothing.
        self::assertSame('0000-0000-00000001-0000-000000000001', $child->getUuid());
        self::assertSame('9000002', $child->getMid());
        // Three levels down, at product_account.product_account_detail.pg_key.
        self::assertSame('CHLD02', $child->getPgKey());
    }

    public function testACorrectlyParentedChildIsRecognised(): void
    {
        $child = $this->parser->parseChildMerchant($this->fixture('02-create-child'));

        self::assertNotNull($child);
        self::assertSame('child_aggregator', $child->getMerchantType());
        self::assertTrue($child->isParentedCorrectly());
    }

    public function testAnOrphanIsNotMistakenForAChild(): void
    {
        // What PayU returns when aggregator_parent_mid was omitted: 200, an account,
        // and merchant_type "aggregator". It looks like success and is not ours.
        $orphan = new OnboardingApiResponse(200, ['product_account' => [
            'uuid' => '0000-0000-00000002-0000-000000000002',
            'mid' => 9000005,
            'merchant_type' => 'aggregator',
        ]], '{}');

        $child = $this->parser->parseChildMerchant($orphan);

        self::assertNotNull($child);
        self::assertFalse($child->isParentedCorrectly());
    }

    public function testKycStatusIsReadFromTheNestedObjectNotTreatedAsAString(): void
    {
        $child = $this->parser->parseChildMerchant($this->fixture('07-get-child-by-uuid'));

        self::assertNotNull($child);
        self::assertSame('LOCKED', $child->getKycStatus());
    }

    /**
     * The finding that matters most in this whole slice: a brand-new sub-merchant,
     * with no bank and no signed Schedule C, already answers "onboarding_completed".
     * Anything that read this as readiness would route money at a dead account.
     */
    public function testABrandNewChildAlreadyReportsOnboardingCompleted(): void
    {
        $child = $this->parser->parseChildMerchant($this->fixture('02-create-child'));

        self::assertNotNull($child);
        self::assertTrue($child->isOnboardingCompleted());
        self::assertFalse($child->isServiceAgreementEsigned());
        self::assertSame('LOCKED', $child->getKycStatus());
        self::assertFalse($child->hasBankAttached());
    }

    /**
     * And document_status regresses, so "it moved forward" is not a usable signal either.
     */
    public function testDocumentStatusGoesBackwardsBetweenCreateAndReadBack(): void
    {
        $atCreation = $this->parser->parseChildMerchant($this->fixture('02-create-child'));
        $readBack = $this->parser->parseChildMerchant($this->fixture('07-get-child-by-uuid'));

        self::assertNotNull($atCreation);
        self::assertNotNull($readBack);
        self::assertSame('Docs Approved', $atCreation->getDocumentStatus());
        self::assertSame('Pending', $readBack->getDocumentStatus());
    }

    public function testBankAttachResponseCarriesTheVerificationStateAndBurnsAnAttempt(): void
    {
        $child = $this->parser->parseChildMerchant($this->fixture('03-attach-bank'));

        self::assertNotNull($child);
        self::assertTrue($child->hasBankAttached());
        self::assertSame(1, $child->getBankUpdateAttemptCount());
        self::assertSame(10, $child->getBankUpdateAttemptLeft());
        self::assertSame('Pending', $child->getBankVerificationStatus());
    }

    public function testPennyDropCompletesByTheTimeTheAccountIsReadBack(): void
    {
        $child = $this->parser->parseChildMerchant($this->fixture('07-get-child-by-uuid'));

        self::assertNotNull($child);
        self::assertSame('Success', $child->getBankVerificationStatus());
        self::assertSame('Success', $child->getPennyDepositStatus());
    }

    public function testTheListingIsParsedAsThinRowsNotAsChildMerchants(): void
    {
        $rows = $this->parser->parseSubAccountList($this->fixture('05-list-sub-accounts'));

        self::assertNotEmpty($rows);
        $first = $rows[0];
        self::assertSame('0000-0000-00000001-0000-000000000001', $first['uuid']);
        self::assertSame('9000002', $first['mid']);
        self::assertSame('CHLD02', $first['pgKey']);
        // The listing sends 1, the full record sends true. Both mean the same thing.
        self::assertTrue($first['onboardingCompleted']);
    }

    public function testAnErrorResponseYieldsNoChildRatherThanAHalfBuiltOne(): void
    {
        self::assertNull($this->parser->parseChildMerchant($this->fixture('04-attach-bank-again')));
    }

    public function testAListingParseOfAnErrorResponseIsEmpty(): void
    {
        self::assertSame([], $this->parser->parseSubAccountList($this->fixture('04-attach-bank-again')));
    }
}
