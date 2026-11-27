<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\CreateMarkClaims;
use RKD\PayuSplit\Split\Service\ExistingChildFinder;
use RKD\PayuSplit\Split\Service\LegacyMappingReset;
use RKD\PayuSplit\Split\Service\OnboardingResponseParser;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use RKD\PayuSplit\Split\Service\VendorOnboardingDataReader;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Reset legacy mapping" against the recorded UAT listing. Its outcome is a NEW PayU account for the
 * vendor, so every refusal here is a case where one already exists.
 */
class LegacyMappingResetTest extends TestCase
{
    private const SELLER_ID = 20;

    private Submerchant $row;
    private AdapterInterface&MockObject $connection;
    private SettlementAuditLogger&MockObject $audit;
    private PayuOnboardingClient&MockObject $client;
    private string $vendorEmail = 'someone-with-no-payu-account@example.in';
    private bool $affiliate = false;

    protected function setUp(): void
    {
        $this->row = $this->submerchant([
            'entity_id' => 2,
            'seller_id' => self::SELLER_ID,
            'onboarding_state' => Submerchant::STATE_NOT_STARTED,
            'payu_merchant_key' => 'sandboxKEY9',
            'is_active' => 1,
        ]);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->audit = $this->createMock(SettlementAuditLogger::class);
        $this->client = $this->createMock(PayuOnboardingClient::class);
        $this->client->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));
    }

    public function testADeadKeyIsClearedPayoutsSwitchOffAndTheVendorIsQueued(): void
    {
        $this->connection->expects(self::once())->method('update')->with(
            'rkd_payu_submerchant',
            self::callback(fn (array $bind): bool => $bind['payu_merchant_key'] === null
                && $bind['payu_mid'] === null
                && $bind['is_active'] === 0
                && $bind['onboarding_state'] === Submerchant::STATE_QUEUED),
            self::callback(fn (array $where): bool => in_array('payu_uuid IS NULL', $where, true)
                && in_array('payu_email IS NULL', $where, true)
                && $where['payu_merchant_key = ?'] === 'sandboxKEY9'
                && $where['entity_id = ?'] === 2
                && $where['seller_id = ?'] === self::SELLER_ID
                && array_key_exists('payu_mid IS NULL', $where)
                && $where['onboarding_state = ?'] === Submerchant::STATE_NOT_STARTED
                && array_key_exists('locked_until IS NULL OR locked_until < ?', $where))
        )->willReturn(1);
        $this->connection->expects(self::once())->method('commit');
        $this->audit->expects(self::once())->method('record')->with(
            AuditLog::ACTION_LEGACY_RESET,
            AuditLog::ENTITY_SUBMERCHANT_ROW,
            2,
            null,
            'legacy',
            Submerchant::STATE_QUEUED,
            self::logicalAnd(self::stringContains('seller 20'), self::stringContains('EY9'), self::logicalNot(self::stringContains('sandboxKEY9')))
        );

        $this->reset()->reset(self::SELLER_ID);
    }

    public function testAMidThatIsOneOfOurChildrenIsNotReset(): void
    {
        $this->row->setData('payu_mid', '9000002');
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/Link PayU account/');
        $this->reset()->reset(self::SELLER_ID);
    }

    /**
     * Wave-6 integrity F1: PayU's listing carries each child's merchant key; a key that is a live
     * child must never be cleared (onboarding would then create a second account).
     */
    public function testAKeyThatIsALiveChildIsNotReset(): void
    {
        $this->row->setData('payu_merchant_key', 'CHLD02');
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/MID ending 0002/');
        $this->reset()->reset(self::SELLER_ID);
    }

    public function testAKeyDifferingOnlyInCaseOrSpacingIsTreatedAsTheLiveOne(): void
    {
        $this->row->setData('payu_merchant_key', ' chld02 ');
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/MID ending 0002/');
        $this->reset()->reset(self::SELLER_ID);
    }

    /**
     * Wave-7 security L-1: a listing row without an account id is dropped by the parser — it must not
     * vanish from the key check.
     */
    public function testAListingRowWithoutAnAccountIdIsNotIgnored(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        unset($listing['child_merchants']['product_accounts'][0]['uuid']);
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $this->client = $client;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/without an account id/');
        $this->reset()->reset(self::SELLER_ID);
    }

    public function testAListingWithoutKeysCannotClearAKey(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        unset($listing['child_merchants']['product_accounts'][1]['product_account_detail']['pg_key']);
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $this->client = $client;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/did not include every merchant key/');
        $this->reset()->reset(self::SELLER_ID);
    }

    public function testAnAccountUnderTheVendorsEmailIsNotReset(): void
    {
        $this->vendorEmail = 'S2-Fixture-260910144139@example.invalid';
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/Link PayU account/');
        $this->reset()->reset(self::SELLER_ID);
    }

    /**
     * @dataProvider notLegacy
     * @param array<string, mixed> $data
     */
    public function testOnlyAnUntouchedHandTypedMappingCanBeReset(array $data): void
    {
        $this->row->addData($data);
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->expectNothingWritten();

        $this->expectException(LocalizedException::class);
        $this->reset()->reset(self::SELLER_ID);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function notLegacy(): array
    {
        return [
            'linked account' => [['payu_uuid' => '0000-0000']],
            'being onboarded' => [['onboarding_state' => Submerchant::STATE_QUEUED]],
            'a create was sent' => [['payu_email' => 'v@x.in']],
            'no key' => [['payu_merchant_key' => null]],
        ];
    }

    public function testAnAffiliateIsNeverQueued(): void
    {
        $this->affiliate = true;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/affiliate/');
        $this->reset()->reset(self::SELLER_ID);
    }

    public function testAPartialListingIsNotTrusted(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        $listing['total_childs'] = 500;
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $this->client = $client;
        $this->expectNothingWritten();

        $this->expectException(\RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException::class);
        $this->reset()->reset(self::SELLER_ID);
    }

    public function testARowThatChangedMeanwhileWritesNothing(): void
    {
        $this->connection->method('update')->willReturn(0);
        $this->connection->expects(self::once())->method('rollBack');
        $this->audit->expects(self::never())->method('record');

        $this->expectExceptionMessageMatches('/changed while/');
        $this->reset()->reset(self::SELLER_ID);
    }

    private function expectNothingWritten(): void
    {
        $this->connection->expects(self::never())->method('update');
        $this->audit->expects(self::never())->method('record');
    }

    private function reset(): LegacyMappingReset
    {
        $repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $repository->method('getBySellerId')->willReturn($this->row);
        $resource = $this->createMock(SubmerchantResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('rkd_payu_submerchant');
        $reader = $this->createMock(VendorOnboardingDataReader::class);
        $reader->method('isApprovedSeller')->willReturn(true);
        $reader->method('hasAffiliateRecord')->willReturnCallback(fn (): bool => $this->affiliate);
        $reader->method('readEmail')->willReturnCallback(fn (): string => $this->vendorEmail);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-24 10:00:00');

        return new LegacyMappingReset(
            $repository,
            $resource,
            $reader,
            new ExistingChildFinder(
                $this->client,
                new OnboardingResponseParser(),
                $this->createMock(CollectionFactory::class),
                $this->createMock(CreateMarkClaims::class)
            ),
            $this->audit,
            $dateTime
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submerchant(array $data): Submerchant
    {
        $model = $this->getMockBuilder(Submerchant::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setIdFieldName('entity_id');
        $model->setData($data);
        return $model;
    }

    private function fixture(string $name): OnboardingApiResponse
    {
        $json = new Json();
        /** @var array<string, mixed> $recorded */
        $recorded = $json->unserialize((string)file_get_contents(__DIR__ . '/../_fixtures/onboarding/' . $name . '.json'));
        $body = is_array($recorded['body'] ?? null) ? $recorded['body'] : [];
        return new OnboardingApiResponse((int)$recorded['http'], $body, $json->serialize($body));
    }
}
