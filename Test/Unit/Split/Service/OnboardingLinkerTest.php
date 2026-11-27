<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\CreateMarkClaims;
use RKD\PayuSplit\Split\Service\ExistingChildFinder;
use RKD\PayuSplit\Split\Service\LinkKeyPolicy;
use RKD\PayuSplit\Split\Service\OnboardingLinker;
use RKD\PayuSplit\Split\Service\PayoutSwitchHistory;
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
 * Staff "Link PayU account", against responses recorded from PayU UAT. Every refusal here is a way
 * a person could tie a vendor's money to the wrong account; every one must write nothing.
 */
class OnboardingLinkerTest extends TestCase
{
    private const SELLER_ID = 42;
    private const UUID = '0000-0000-00000001-0000-000000000001';
    private const MID = '9000002';
    private const PG_KEY = 'CHLD02';

    private Submerchant $row;
    private PayuOnboardingClient&MockObject $client;
    private AdapterInterface&MockObject $connection;
    private SettlementAuditLogger&MockObject $audit;
    private const ACCOUNT_EMAIL = 's2-fixture-260910144139@example.invalid';

    private int $otherOwners = 0;
    private int $emailClaimedElsewhere = 0;
    private ?string $claimantUuid = null;
    private bool $switchedOffByStaff = false;
    private string $vendorEmail = self::ACCOUNT_EMAIL;
    /** @var array<int, array{0: mixed, 1: mixed}> */
    private array $filters = [];
    private bool $approved = true;
    private bool $affiliate = false;

    protected function setUp(): void
    {
        $this->row = $this->submerchant([
            'entity_id' => 7,
            'seller_id' => self::SELLER_ID,
            'onboarding_state' => Submerchant::STATE_FAILED,
        ]);
        $this->client = $this->createMock(PayuOnboardingClient::class);
        $this->client->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->audit = $this->createMock(SettlementAuditLogger::class);
    }

    public function testAFailedVendorIsLinkedByMidAndQueuedForTheWorker(): void
    {
        $this->connection->expects(self::once())->method('update')->with(
            'rkd_payu_submerchant',
            self::callback(fn (array $bind): bool => $bind['payu_uuid'] === self::UUID
                && $bind['payu_mid'] === self::MID
                && $bind['payu_merchant_key'] === self::PG_KEY
                && $bind['onboarding_state'] === Submerchant::STATE_QUEUED
                && !array_key_exists('is_active', $bind)),
            self::callback(fn (array $where): bool => in_array('payu_uuid IS NULL', $where, true)
                && $where['onboarding_state = ?'] === Submerchant::STATE_FAILED)
        )->willReturn(1);
        $this->connection->expects(self::once())->method('commit');
        $this->audit->expects(self::once())->method('record')->with(
            AuditLog::ACTION_ONBOARDING_LINK,
            AuditLog::ENTITY_SUBMERCHANT_ROW,
            7,
            null,
            Submerchant::STATE_FAILED,
            Submerchant::STATE_QUEUED,
            self::stringContains('0002')
        );

        $child = $this->linker()->link(self::SELLER_ID, ' ' . self::MID . ' ');

        self::assertSame(self::UUID, $child->getUuid());
    }

    public function testALegacyRowLinksOnlyToTheAccountCarryingItsKey(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED, 'payu_merchant_key' => self::PG_KEY]);
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::callback(fn (array $bind): bool => !array_key_exists('payu_merchant_key', $bind)),
            self::anything()
        )->willReturn(1);

        $this->linker()->link(self::SELLER_ID, self::UUID);
    }

    public function testALegacyRowIsNotLinkedToAnAccountWithADifferentKey(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED, 'payu_merchant_key' => 'CHLD01']);
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/merchant key already on file.*Deactivate payouts/s');
        $this->linker()->link(self::SELLER_ID, self::UUID);
    }

    public function testAnAccountNotUnderOurParentIsRefused(): void
    {
        $this->client->expects(self::never())->method('fetchChildMerchant');
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/under our PayU parent/');
        $this->linker()->link(self::SELLER_ID, '99999999');
    }

    public function testAnOrphanIsRefusedEvenIfListed(): void
    {
        $read = $this->fixture('02-create-child')->body();
        $read['product_account']['merchant_type'] = 'aggregator';
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));
        $client->method('fetchChildMerchant')->willReturn(new OnboardingApiResponse(200, $read, ''));
        $this->client = $client;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/orphan/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    public function testAnAccountAnotherSellerHoldsIsRefused(): void
    {
        $this->otherOwners = 1;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/already linked to seller/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * @dataProvider notLinkable
     * @param array<string, mixed> $data
     */
    public function testRowsTheWorkerOwnsAreNotLinkedByHand(array $data, string $why): void
    {
        $this->row->addData($data);
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches($why);
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function notLinkable(): array
    {
        return [
            'already linked' => [['payu_uuid' => self::UUID], '/already linked/'],
            'queued' => [['onboarding_state' => Submerchant::STATE_QUEUED], '/being onboarded/'],
            'awaiting e-sign' => [['onboarding_state' => Submerchant::STATE_AWAITING_ESIGN], '/being onboarded/'],
        ];
    }

    public function testAnAffiliateIsNeverLinked(): void
    {
        $this->affiliate = true;
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/affiliate/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    public function testAVendorNoLongerApprovedIsNeverLinked(): void
    {
        $this->approved = false;
        $this->expectNothingWritten();

        $this->expectException(LocalizedException::class);
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    public function testARowThatChangedMeanwhileWritesNothing(): void
    {
        $this->connection->method('update')->willReturn(0);
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::never())->method('commit');
        $this->audit->expects(self::never())->method('record');

        $this->expectExceptionMessageMatches('/changed while/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * Wave-3 I-F3: a child made by ANOTHER vendor's lost create has no uuid stored against it yet;
     * its email (recorded as that row's payu_email) is the only thing that says whose it is.
     */
    public function testAnAccountAnotherVendorsCreateMadeIsNotLinkedHere(): void
    {
        $this->emailClaimedElsewhere = 1;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/created for seller #18/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * Wave-9 integrity M-1: two vendors' creates went out with the same email and the worker stops for
     * a person. The person's way through is Link on the vendor the account is — here THIS row, which is
     * itself a claimant (its own mark), even though its current email has moved on. The other claimant
     * is named in the audit.
     */
    public function testASharedClaimIsResolvedByLinkingAClaimantAndTheOtherIsAudited(): void
    {
        $this->row->setData('payu_email', strtoupper(self::ACCOUNT_EMAIL));
        $this->vendorEmail = 'moved.on@example.in';
        $this->emailClaimedElsewhere = 1;
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::anything(),
            self::callback(fn (array $where): bool => ($where['payu_email = ?'] ?? null) === strtoupper(self::ACCOUNT_EMAIL)
                && array_key_exists("(payu_merchant_key IS NULL OR payu_merchant_key = '')", $where))
        )->willReturn(1);
        $this->audit->expects(self::once())->method('record')->with(
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::stringContains('also recorded for seller(s) 18')
        );

        $this->linker()->link(self::SELLER_ID, self::MID, [18]);
    }

    /**
     * Wave-10 integrity M-1: the contest is shown BEFORE the one-shot write, naming the other seller and
     * the exact text that confirms it; a wrong or partial confirmation still writes nothing.
     *
     * @dataProvider unconfirmedClaims
     * @param int[] $confirmed
     */
    public function testASharedClaimIsRefusedUntilStaffConfirmTheOtherClaimantById(array $confirmed): void
    {
        $this->row->setData('payu_email', self::ACCOUNT_EMAIL);
        $this->emailClaimedElsewhere = 1;
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/#18 .*MID ending 0002.*enter: ' . self::MID . ' confirm 18\./s');
        $this->linker()->link(self::SELLER_ID, self::MID, $confirmed);
    }

    /**
     * @return array<string, array{int[]}>
     */
    public static function unconfirmedClaims(): array
    {
        return ['none' => [[]], 'wrong seller' => [[19]], 'extra seller' => [[18, 19]]];
    }

    /**
     * Wave-10 integrity M-3: a key staff distrust (payouts switched off) is replaced by the account's own
     * key — but only under the keyless email rule, and the link ENDS the on/off history.
     */
    public function testASwitchedOffLegacyRowTakesTheKeyFromPayuAndEndsItsHistory(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED, 'payu_merchant_key' => 'abce12']);
        $this->switchedOffByStaff = true;
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::callback(fn (array $bind): bool => ($bind['payu_merchant_key'] ?? null) === self::PG_KEY),
            self::callback(fn (array $where): bool => ($where['payu_merchant_key = ?'] ?? null) === 'abce12'
                && ($where['is_active = ?'] ?? null) === 0)
        )->willReturn(1);
        $this->audit->expects(self::once())->method('record')->with(
            AuditLog::ACTION_ONBOARDING_LINK,
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::stringContains('replaced key ending ce12')
        );

        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * Wave-11 integrity M-1: the key on file holds its account against every other row. If a listed
     * account carries it (loosely, like Reset) or the stored MID, replacing it would release a live
     * account for another vendor to adopt — refused; so is a listing that does not show every key.
     *
     * @dataProvider liveKeysOnFile
     * @param array<string, mixed> $data
     */
    public function testALegacyKeyThatNamesALiveAccountIsNeverReplaced(array $data, ?callable $listing, string $why): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED] + $data);
        $this->switchedOffByStaff = true;
        if ($listing !== null) {
            $body = $this->fixture('05-list-sub-accounts')->body();
            $body['child_merchants']['product_accounts'] = $listing($body['child_merchants']['product_accounts']);
            $client = $this->createMock(PayuOnboardingClient::class);
            $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $body, ''));
            $client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
            $this->client = $client;
        }
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches($why);
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * @return array<string, array{array<string, mixed>, callable|null, string}>
     */
    public static function liveKeysOnFile(): array
    {
        return [
            'another listed account carries the key (case/space differ)' => [
                ['payu_merchant_key' => ' chld03 '], null, '/belongs to a PayU sub-merchant that exists \(MID ending 0003\)/',
            ],
            'the stored MID is a listed account' => [
                ['payu_merchant_key' => 'abce12', 'payu_mid' => '9000004'], null, '/MID ending 0004/',
            ],
            'the listing hides a key' => [
                ['payu_merchant_key' => 'abce12'],
                static function (array $rows): array {
                    unset($rows[1]['product_account_detail']['pg_key']);
                    return $rows;
                },
                '/did not include every merchant key/',
            ],
        ];
    }

    /**
     * Wave-12 integrity M-1: a key or MID that already points at the account being linked frees nothing, so
     * it is not a reason to refuse — a typo'd key beside the right MID, or a key wrong only in case, links.
     *
     * @dataProvider typosOfTheTargetItself
     * @param array<string, mixed> $data
     */
    public function testATypoOfTheLinkedAccountsOwnKeyStillLinks(array $data): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED] + $data);
        $this->switchedOffByStaff = true;
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::callback(fn (array $bind): bool => ($bind['payu_merchant_key'] ?? null) === self::PG_KEY),
            self::anything()
        )->willReturn(1);

        self::assertSame(self::UUID, $this->linker()->link(self::SELLER_ID, self::MID)->getUuid());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function typosOfTheTargetItself(): array
    {
        return [
            'typo key, right MID on file' => [['payu_merchant_key' => 'abce12', 'payu_mid' => ' ' . self::MID]],
            'right key, wrong case' => [['payu_merchant_key' => 'chld02']],
        ];
    }

    /**
     * Wave-12 integrity M-2: Link reads the same complete listing as onboarding and Reset — a row without a
     * uuid, or fewer rows than the total, is a retry, never a green light to release a key.
     *
     * @dataProvider incompleteListings
     */
    public function testAnIncompleteListingNeverLetsAKeyBeReplaced(callable $mangle): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED, 'payu_merchant_key' => 'LiveK9']);
        $this->switchedOffByStaff = true;
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $mangle($this->fixture('05-list-sub-accounts')->body()), ''));
        $client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client = $client;
        $this->expectNothingWritten();

        $this->expectException(PayuOnboardingRequestException::class);
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * @return array<string, array{callable}>
     */
    public static function incompleteListings(): array
    {
        return [
            'a row without a uuid (carrying the live key)' => [static function (array $body): array {
                $body['child_merchants']['product_accounts'][] = [
                    'mid' => 87990000, 'email' => 'live@example.invalid', 'product_account_detail' => ['pg_key' => 'LiveK9'],
                ];
                $body['total_childs'] = count($body['child_merchants']['product_accounts']);
                return $body;
            }],
            'fewer rows than the total' => [static function (array $body): array {
                $body['total_childs'] = 5;
                return $body;
            }],
        ];
    }

    public function testASwitchedOffLegacyRowStillNeedsTheVendorsEmailToTakeAKey(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED, 'payu_merchant_key' => 'abce12']);
        $this->switchedOffByStaff = true;
        $this->vendorEmail = 'someone.else@example.in';
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/not registered with seller 42/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * Wave-13 integrity Low: a key written from PayU only ever lands on a row that is off. A keyless row
     * that is somehow active is refused by the pin, not given a key that routes money before bank and e-sign.
     */
    public function testAKeyFromPayuIsOnlyWrittenOntoARowThatIsOff(): void
    {
        $this->row->setData('is_active', 1);
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::anything(),
            self::callback(fn (array $where): bool => ($where['is_active = ?'] ?? null) === 0)
        )->willReturn(0);

        $this->expectExceptionMessageMatches('/changed while/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    public function testTheWriteIsPinnedOnTheSeller(): void
    {
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::anything(),
            self::callback(fn (array $where): bool => ($where['seller_id = ?'] ?? null) === self::SELLER_ID)
        )->willReturn(1);

        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * Wave-9 integrity Low: another row's mark that its own account explains is dead and refuses nothing.
     * Here the claimant holds a listed account under a different email, so its mark is still LIVE.
     */
    public function testAClaimantHoldingADifferentAccountStillRefusesANonClaimant(): void
    {
        $this->emailClaimedElsewhere = 1;
        $this->claimantUuid = '0000-0000-00000003-0000-000000000003';
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/created for seller #18/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    /**
     * Wave-9 security L-2: the write is pinned on the key the checks saw.
     */
    public function testALegacyLinkIsPinnedOnItsKey(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_NOT_STARTED, 'payu_merchant_key' => self::PG_KEY]);
        $this->connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::anything(),
            self::callback(fn (array $where): bool => ($where['payu_merchant_key = ?'] ?? null) === self::PG_KEY
                && array_key_exists('payu_email IS NULL', $where))
        )->willReturn(1);

        $this->linker()->link(self::SELLER_ID, self::UUID);
    }

    public function testAKeylessRowIsNotLinkedToAnAccountUnderSomeoneElsesEmail(): void
    {
        $this->vendorEmail = 'someone.else@example.in';
        $this->expectNothingWritten();

        $this->expectExceptionMessageMatches('/not registered with seller 42/');
        $this->linker()->link(self::SELLER_ID, self::MID);
    }

    public function testTheOwnerCheckLooksAtEveryOtherRowOnUuidMidAndKey(): void
    {
        $this->connection->method('update')->willReturn(1);

        $this->linker()->link(self::SELLER_ID, self::MID);

        self::assertContains(
            [['payu_uuid', 'payu_mid', 'payu_merchant_key'], [['eq' => self::UUID], ['eq' => self::MID], ['eq' => self::PG_KEY]]],
            $this->filters
        );
        self::assertContains(['seller_id', ['neq' => self::SELLER_ID]], $this->filters);
        // The payu_email claim check must look at OTHER rows only, or a vendor's own mark would block it.
        $emailQuery = array_search(['payu_email', self::ACCOUNT_EMAIL], $this->filters, true);
        self::assertNotFalse($emailQuery);
        self::assertSame(['seller_id', ['neq' => self::SELLER_ID]], $this->filters[$emailQuery + 1]);
    }

    public function testRubbishInputNeverReachesPayu(): void
    {
        $this->client->expects(self::never())->method('listSubAccounts');

        $this->expectExceptionMessageMatches('/MID \(digits\) or the account uuid/');
        $this->linker()->link(self::SELLER_ID, "x' OR 1=1 --");
    }

    // ---------------------------------------------------------------- helpers

    private function expectNothingWritten(): void
    {
        $this->connection->expects(self::never())->method('update');
        $this->audit->expects(self::never())->method('record');
    }

    private function linker(): OnboardingLinker
    {
        $repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $repository->method('getBySellerId')->willReturn($this->row);

        $resource = $this->createMock(SubmerchantResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('rkd_payu_submerchant');

        $collections = $this->createMock(CollectionFactory::class);
        $collections->method('create')->willReturnCallback(function () {
            $asked = [];
            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $condition) use (&$asked, &$collection) {
                    $asked[] = [$field, $condition];
                    $this->filters[] = [$field, $condition];
                    return $collection;
                }
            );
            $collection->method('getSize')->willReturnCallback(function () use (&$asked): int {
                return $asked[0][0] === 'payu_email' ? $this->emailClaimedElsewhere : $this->otherOwners;
            });
            $collection->method('getFirstItem')->willReturn($this->submerchant(['seller_id' => 18]));
            $collection->method('getIterator')->willReturnCallback(function () use (&$asked): \ArrayIterator {
                $claims = $asked[0][0] === 'payu_email' && ($asked[1] ?? null) === ['seller_id', ['neq' => self::SELLER_ID]];
                return new \ArrayIterator($claims ? array_fill(0, $this->emailClaimedElsewhere, $this->submerchant([
                    'seller_id' => 18, 'payu_email' => $asked[0][1], 'payu_uuid' => $this->claimantUuid,
                ])) : [$this->submerchant(['seller_id' => self::SELLER_ID])]);
            });
            return $collection;
        });

        $reader = $this->createMock(VendorOnboardingDataReader::class);
        $reader->method('isApprovedSeller')->willReturnCallback(fn (): bool => $this->approved);
        $reader->method('hasAffiliateRecord')->willReturnCallback(fn (): bool => $this->affiliate);
        $reader->method('readEmail')->willReturnCallback(fn (): string => $this->vendorEmail);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-24 10:00:00');
        $history = $this->createMock(PayoutSwitchHistory::class);
        $history->method('isSwitchedOffByStaff')->willReturnCallback(fn (): bool => $this->switchedOffByStaff);

        return new OnboardingLinker(
            $repository,
            $resource,
            $collections,
            $reader,
            $this->client,
            new OnboardingResponseParser(),
            $this->audit,
            $dateTime,
            new CreateMarkClaims($collections),
            new LinkKeyPolicy($history),
            new ExistingChildFinder($this->client, new OnboardingResponseParser(), $collections, new CreateMarkClaims($collections))
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submerchant(array $data): Submerchant
    {
        $model = $this->getMockBuilder(Submerchant::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
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
