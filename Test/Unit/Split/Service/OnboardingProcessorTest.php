<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuOnboardingCredentialsException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingNotSentException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\ChildMerchantDraft;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\SettlementBankDetail;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\BusinessEntityResolver;
use RKD\PayuSplit\Split\Service\CreateMarkClaims;
use RKD\PayuSplit\Split\Service\ExistingChildFinder;
use RKD\PayuSplit\Split\Service\OnboardingAlerter;
use RKD\PayuSplit\Split\Service\OnboardingClaim;
use RKD\PayuSplit\Split\Service\OnboardingPause;
use RKD\PayuSplit\Split\Service\OnboardingPreflight;
use RKD\PayuSplit\Split\Service\OnboardingProcessor;
use RKD\PayuSplit\Split\Service\OnboardingResponseParser;
use RKD\PayuSplit\Split\Service\OnboardingSteps;
use RKD\PayuSplit\Split\Exception\OnboardingLeaseLostException;
use RKD\PayuSplit\Split\Service\VendorOnboardingDataReader;
use Magento\Framework\FlagManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The onboarding worker, driven with responses RECORDED from PayU UAT.
 *
 * Every test here guards one way of creating a second undeletable PayU account, spending the
 * one-shot bank attempt, or leaving a vendor stuck without anyone knowing.
 */
class OnboardingProcessorTest extends TestCase
{
    private const SELLER_ID = 42;
    private const NOW_TS = 1790244000; // 2026-09-24 10:00:00 UTC
    private const FIXTURE_UUID = '0000-0000-00000001-0000-000000000001';
    private const FIXTURE_EMAIL = 's2-fixture-260910144139@example.invalid';
    private const NEW_EMAIL = 'fresh-vendor@example.invalid';
    public const NEW_EMAIL_PUBLIC = self::NEW_EMAIL;

    private Submerchant $row;
    /** @var string[] */
    private array $savedStates = [];
    private ?Submerchant $uuidOwner = null;

    private OnboardingConfig $config;
    private OnboardingClaim $claim;
    private VendorOnboardingDataReader $reader;
    private PayuOnboardingClient $client;
    private OnboardingAlerter&MockObject $alerter;
    private OnboardingProcessor $processor;

    protected function setUp(): void
    {
        $this->row = $this->submerchant([
            'entity_id' => 7,
            'seller_id' => self::SELLER_ID,
            'onboarding_state' => Submerchant::STATE_QUEUED,
            'is_active' => 0,
            'attempts' => 0,
        ]);

        $test = $this;
        $repository = new class ($test) implements SubmerchantRepositoryInterface {
            public function __construct(private readonly OnboardingProcessorTest $test)
            {
            }

            public function getBySellerId(int $sellerId): ?Submerchant
            {
                return $this->test->currentRow();
            }

            public function getByPayuUuid(string $uuid): ?Submerchant
            {
                return $this->test->uuidOwner();
            }

            public function getById(int $entityId): ?Submerchant
            {
                return $this->test->currentRow();
            }

            public function getByPayuMidOrKey(string $mid, ?string $merchantKey): ?Submerchant
            {
                return $this->test->midOwner();
            }

            public function save(Submerchant $submerchant): Submerchant
            {
                $this->test->recordSave($submerchant);
                return $submerchant;
            }
        };

        $this->config = $this->createMock(OnboardingConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isConfigured')->willReturn(true);

        $this->claim = $this->createMock(OnboardingClaim::class);
        $this->claim->method('claim')->willReturn(true);
        $this->claim->method('renew')->willReturn(true);

        $this->reader = $this->createMock(VendorOnboardingDataReader::class);
        $this->reader->method('isApprovedSeller')->willReturn(true);
        $this->reader->method('hasAffiliateRecord')->willReturn(false);
        $this->reader->method('readLegalType')->willReturn('proprietorship');
        $this->reader->method('readShopName')->willReturn('Test Ortho Supplies');
        $this->reader->method('readBank')->willReturn(
            new SettlementBankDetail('50100000000000', 'HDFC0000000', 'Test Vendor')
        );
        $this->reader->method('readDraft')->willReturnCallback(fn (): ChildMerchantDraft => $this->currentDraft());

        $this->client = $this->createMock(PayuOnboardingClient::class);
        $this->client->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));

        $this->alerter = $this->createMock(OnboardingAlerter::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TS);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null, $input = null): string => gmdate(
                $format ?? 'Y-m-d H:i:s',
                $input === null ? self::NOW_TS : (int)$input
            )
        );

        $this->repository = $repository;
        $this->dateTime = $dateTime;
        $this->build();
    }

    private SubmerchantRepositoryInterface $repository;
    private DateTime $dateTime;

    private function build(): void
    {
        $logger = $this->createMock(Logger::class);
        $steps = new OnboardingSteps(
            $this->repository,
            $this->reader,
            new OnboardingPreflight(new BusinessEntityResolver()),
            $this->client,
            new OnboardingResponseParser(),
            $this->dateTime,
            $logger,
            new ExistingChildFinder(
                $this->client,
                new OnboardingResponseParser(),
                $this->markCollections(),
                new CreateMarkClaims($this->markCollections())
            )
        );
        $this->processor = new OnboardingProcessor(
            $this->config,
            $this->repository,
            $this->claim,
            $this->reader,
            $steps,
            $this->alerter,
            $this->dateTime,
            $logger,
            new OnboardingPause($this->flagManager(), $this->dateTime)
        );
    }

    private function flagManager(): FlagManager
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->pauseFlag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value): bool {
            $this->pauseFlag = (string)$value;
            return true;
        });
        $flags->method('deleteFlag')->willReturnCallback(function (): bool {
            $this->pauseFlag = null;
            return true;
        });
        return $flags;
    }

    /** Rows of OTHER sellers whose create was sent with the same email. */
    private int $otherRowsWithMark = 0;

    /** The account those rows hold ('' = none yet). */
    private string $otherMarkUuid = '';

    /** Rows of OTHER sellers already holding the matched child's uuid. */
    private int $childOwnedElsewhere = 0;

    /**
     * Filter-aware: answers per query, and only when the query excludes THIS seller — so a check that
     * forgot "seller_id != self" would count the vendor's own row and fail the tests.
     */
    private function markCollections(): CollectionFactory
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $asked = [];
            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $condition) use (&$asked, &$collection) {
                    $asked[is_array($field) ? implode('|', $field) : $field] = $condition;
                    return $collection;
                }
            );
            $collection->method('getSize')->willReturnCallback(function () use (&$asked): int {
                if (($asked['seller_id'] ?? null) !== ['neq' => self::SELLER_ID]) {
                    return 1; // the vendor's own row would match: a missing exclusion shows up as a refusal
                }
                return $this->childOwnedElsewhere; // "is this child held by another row?"
            });
            // "whose create shares this mark?" — CreateMarkClaims reads each row, only when THIS seller is excluded
            $collection->method('getIterator')->willReturnCallback(function () use (&$asked): \ArrayIterator {
                if (!isset($asked['payu_email']) || ($asked['seller_id'] ?? null) !== ['neq' => self::SELLER_ID]) {
                    return new \ArrayIterator([$this->submerchant(['seller_id' => self::SELLER_ID])]);
                }
                return new \ArrayIterator(array_fill(0, $this->otherRowsWithMark, $this->submerchant([
                    'seller_id' => 77, 'payu_email' => $asked['payu_email'], 'payu_uuid' => $this->otherMarkUuid ?: null,
                ])));
            });
            $collection->method('getFirstItem')->willReturnCallback(fn () => $this->submerchant(['seller_id' => 77]));
            return $collection;
        });
        return $factory;
    }

    public function currentRow(): Submerchant
    {
        return $this->row;
    }

    public function uuidOwner(): ?Submerchant
    {
        return $this->uuidOwner;
    }

    public function midOwner(): ?Submerchant
    {
        return $this->midOwner;
    }

    private ?Submerchant $midOwner = null;
    private ?string $pauseFlag = null;

    public function recordSave(Submerchant $row): void
    {
        $this->savedStates[] = $row->getOnboardingState();
    }

    public function testHappyPathCreatesAttachesTheBankAndHandsTheVendorToStaff(): void
    {
        $this->client->expects(self::once())->method('createChildMerchant')
            ->willReturn($this->fixture('02-create-child'));
        // Read back before the bank call: freshly created, no bank yet.
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->expects(self::once())->method('attachBankDetail')
            ->with(self::FIXTURE_UUID, '50100000000000', 'HDFC0000000', 'Test Vendor')
            ->willReturn($this->fixture('03-attach-bank'));
        $this->alerter->expects(self::once())->method('alert')
            ->with(self::SELLER_ID, 'Test Ortho Supplies', 'send the Schedule C e-sign', self::anything(), false);

        $outcome = $this->processor->process(self::SELLER_ID);

        self::assertSame(OnboardingProcessor::OUTCOME_AWAITING_ESIGN, $outcome);
        self::assertSame(Submerchant::STATE_AWAITING_ESIGN, $this->row->getOnboardingState());
        self::assertSame(self::FIXTURE_UUID, $this->row->getPayuUuid());
        self::assertSame('9000002', $this->row->getPayuMid());
        self::assertSame('CHLD02', $this->row->getMerchantKey());
        self::assertContains(Submerchant::STATE_CREATED, $this->savedStates);
        self::assertContains(Submerchant::STATE_BANK_ATTACHED, $this->savedStates);
    }

    public function testTheVendorIsNeverMadeActiveByOnboarding(): void
    {
        // Production's older split engine pays any is_active row and has no off switch.
        $this->client->method('createChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));

        $this->processor->process(self::SELLER_ID);

        self::assertFalse($this->row->isActive());
    }

    public function testAnExistingChildWithTheVendorsEmailIsAdoptedNotCreatedAgain(): void
    {
        // The crash case: we sent the create (email recorded first), PayU made the child, and we
        // died before saving the uuid.
        $this->withDraft(self::FIXTURE_EMAIL);
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->client->expects(self::never())->method('createChildMerchant');
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('07-get-child-by-uuid'));

        $this->processor->process(self::SELLER_ID);

        self::assertSame(self::FIXTURE_UUID, $this->row->getPayuUuid());
        self::assertSame(Submerchant::STATE_AWAITING_ESIGN, $this->row->getOnboardingState());
    }

    public function testAStoredUuidSkipsTheListingAndTheCreate(): void
    {
        $this->row->setData('payu_uuid', self::FIXTURE_UUID);
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->client->expects(self::never())->method('createChildMerchant');
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('07-get-child-by-uuid'));

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_AWAITING_ESIGN, $this->row->getOnboardingState());
    }

    public function testABankPayuAlreadyHoldsIsNeverSentAgain(): void
    {
        // Our row says CREATED, but PayU's own counter says the bank went through (T-06, N-4).
        $this->row->addData(['onboarding_state' => Submerchant::STATE_CREATED, 'payu_uuid' => self::FIXTURE_UUID]);
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('07-get-child-by-uuid'));
        $this->client->expects(self::never())->method('attachBankDetail');

        $outcome = $this->processor->process(self::SELLER_ID);

        self::assertSame(OnboardingProcessor::OUTCOME_AWAITING_ESIGN, $outcome);
    }

    public function testPayusBankAlreadySubmittedRefusalCountsAsAttached(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_CREATED, 'payu_uuid' => self::FIXTURE_UUID]);
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('attachBankDetail')->willReturn($this->fixture('04-attach-bank-again'));

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_AWAITING_ESIGN, $this->row->getOnboardingState());
    }

    public function testATransportFailureKeepsTheStateAndSchedulesARetry(): void
    {
        // T-09: PayU unreachable — state unchanged, reason stored, retried later.
        $this->client->method('createChildMerchant')
            ->willThrowException(new PayuOnboardingRequestException('PayU onboarding "create" failed at transport level.'));
        $this->alerter->expects(self::never())->method('alert');

        $outcome = $this->processor->process(self::SELLER_ID);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $outcome);
        self::assertSame(Submerchant::STATE_QUEUED, $this->row->getOnboardingState());
        self::assertSame(1, $this->row->getAttempts());
        self::assertSame(Submerchant::ERROR_RETRYABLE, $this->row->getErrorClass());
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW_TS + 60), $this->row->getNextAttemptAt());
        self::assertStringContainsString('transport', (string)$this->row->getLastError());
    }

    public function testRetriesRunOutIntoFailedAndSomeoneIsTold(): void
    {
        $this->row->setData('attempts', 5);
        $this->client->method('createChildMerchant')
            ->willThrowException(new PayuOnboardingRequestException('HTTP 503'));
        $this->alerter->expects(self::once())->method('alert')
            ->with(self::SELLER_ID, self::anything(), 'onboarding failed', self::anything(), true);

        $outcome = $this->processor->process(self::SELLER_ID);

        self::assertSame(OnboardingProcessor::OUTCOME_FAILED, $outcome);
        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertSame(Submerchant::ERROR_RETRYABLE, $this->row->getErrorClass());
    }

    public function testAPayuRejectionFailsAtOnceWithPayusReason(): void
    {
        $this->client->method('createChildMerchant')->willReturn(new OnboardingApiResponse(
            422,
            ['errors' => ['pancard_number' => ['is invalid']]],
            ''
        ));
        $this->alerter->expects(self::once())->method('alert');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertSame(Submerchant::ERROR_BUSINESS, $this->row->getErrorClass());
        self::assertStringContainsString('is invalid', (string)$this->row->getLastError());
    }

    public function testBadVendorDataNeverReachesPayu(): void
    {
        // A company PAN ("C") on a proprietorship: PayU would reject it, and a wrong child is permanent.
        $this->withDraft(self::NEW_EMAIL, 'AAACA0000A');
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->client->expects(self::never())->method('createChildMerchant');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertStringContainsString('PAN category letter "C"', (string)$this->row->getLastError());
        self::assertStringNotContainsString('AAACA0000A', (string)$this->row->getLastError());
    }

    public function testAnUnusableBankRecordStopsTheVendorBeforeAChildExists(): void
    {
        // Otherwise the vendor gets a child they can never be paid through.
        $this->reader = $this->rebuildReaderWithBank(new SettlementBankDetail('', 'HDFC0000000', 'Test Vendor'));
        $this->client->expects(self::never())->method('createChildMerchant');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertStringContainsString('Settlement account number', (string)$this->row->getLastError());
    }

    public function testAVendorNoLongerApprovedIsNotSent(): void
    {
        $reader = $this->createMock(VendorOnboardingDataReader::class);
        $reader->method('isApprovedSeller')->willReturn(false);
        $this->swapReader($reader);
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->client->expects(self::never())->method('createChildMerchant');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
    }

    public function testACustomerWhoIsAlsoAnAffiliateIsRefused(): void
    {
        $reader = $this->createMock(VendorOnboardingDataReader::class);
        $reader->method('isApprovedSeller')->willReturn(true);
        $reader->method('hasAffiliateRecord')->willReturn(true);
        $this->swapReader($reader);
        $this->client->expects(self::never())->method('createChildMerchant');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertStringContainsString('affiliate', (string)$this->row->getLastError());
    }

    public function testAnOrphanIsRecordedSoItIsNeverCreatedAgainButNeverGetsAKey(): void
    {
        $orphan = $this->fixture('02-create-child');
        $body = $orphan->body();
        $body['product_account']['merchant_type'] = 'aggregator';
        $this->client->method('createChildMerchant')->willReturn(new OnboardingApiResponse(200, $body, ''));
        $this->alerter->expects(self::once())->method('alert')->with(
            self::SELLER_ID,
            self::anything(),
            'onboarding failed',
            self::anything(),
            true
        );

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertSame(Submerchant::ERROR_ORPHAN, $this->row->getErrorClass());
        self::assertSame(self::FIXTURE_UUID, $this->row->getPayuUuid());
        self::assertFalse($this->row->hasMerchantKey());
    }

    public function testAChildAlreadyLinkedToAnotherSellerIsNotAdopted(): void
    {
        $this->withDraft(self::FIXTURE_EMAIL);
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->uuidOwner = $this->submerchant(['seller_id' => 99]);
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('07-get-child-by-uuid'));
        $this->client->expects(self::never())->method('createChildMerchant');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertStringContainsString('seller #99', (string)$this->row->getLastError());
    }

    public function testAFailedListingNeverFallsThroughToCreate(): void
    {
        // Without the listing we cannot rule out a duplicate, so we do not create.
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(404, ['error' => 'not found'], ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        $outcome = $this->processor->process(self::SELLER_ID);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $outcome);
        self::assertSame(Submerchant::STATE_QUEUED, $this->row->getOnboardingState());
    }

    public function testRowsHeldByAnotherRunAreSkipped(): void
    {
        $claim = $this->createMock(OnboardingClaim::class);
        $claim->method('claim')->willReturn(false);
        $this->swapClaim($claim);
        $this->client->expects(self::never())->method('listSubAccounts');

        self::assertSame(OnboardingProcessor::OUTCOME_LOCKED, $this->processor->process(self::SELLER_ID));
    }

    public function testNothingHappensWhileSwitchedOff(): void
    {
        $config = $this->createMock(OnboardingConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $this->swapConfig($config);
        $this->client->expects(self::never())->method('listSubAccounts');

        self::assertSame(OnboardingProcessor::OUTCOME_DISABLED, $this->processor->process(self::SELLER_ID));
        self::assertSame([], $this->savedStates);
    }

    public function testAVendorInBackoffWaitsUnlessForced(): void
    {
        $this->row->setData('next_attempt_at', gmdate('Y-m-d H:i:s', self::NOW_TS + 300));
        $this->client->method('createChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));

        self::assertSame(OnboardingProcessor::OUTCOME_NOT_DUE, $this->processor->process(self::SELLER_ID));
        self::assertSame(
            OnboardingProcessor::OUTCOME_AWAITING_ESIGN,
            $this->processor->process(self::SELLER_ID, true)
        );
    }

    public function testStatesThatNeedAHumanAreLeftAlone(): void
    {
        foreach ([Submerchant::STATE_AWAITING_ESIGN, Submerchant::STATE_FAILED, Submerchant::STATE_ACTIVE] as $state) {
            $this->row->setData('onboarding_state', $state);
            self::assertSame(OnboardingProcessor::OUTCOME_NOTHING_TO_DO, $this->processor->process(self::SELLER_ID));
        }
    }

    public function testOnlyThisRunsLeaseIsReleasedAndNothingElseIsWritten(): void
    {
        $claim = $this->createMock(OnboardingClaim::class);
        $claim->method('claim')->willReturn(true);
        $claim->method('renew')->willReturn(true);
        // Renewed right before the create (strictly later than the claim), and that renewed
        // lease — this run's current one — is the one given back.
        $claim->expects(self::once())->method('release')
            ->with(7, gmdate('Y-m-d H:i:s', self::NOW_TS + 601));
        $this->swapClaim($claim);
        $this->client->method('createChildMerchant')
            ->willThrowException(new PayuOnboardingRequestException('down'));

        $this->processor->process(self::SELLER_ID);
    }

    public function testAVendorWeNeverSentIsNotLinkedToAnAccountThatMerelySharesTheirEmail(): void
    {
        // PayU holds a child with this email, but ALLYNOR never created one for this vendor
        // (no payu_email). It may be someone else's account: never adopt it automatically.
        $this->withDraft(self::FIXTURE_EMAIL);
        $this->client->expects(self::never())->method('createChildMerchant');
        $this->client->expects(self::never())->method('fetchChildMerchant');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertNull($this->row->getPayuUuid());
        self::assertStringContainsString('never created one', (string)$this->row->getLastError());
    }

    public function testARetryAdoptsOnTheEmailTheCreateWasSentWithEvenAfterTheVendorChangesIt(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->withDraft('changed-since@example.invalid');
        $this->client->expects(self::never())->method('createChildMerchant');
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('07-get-child-by-uuid'));

        $this->processor->process(self::SELLER_ID);

        self::assertSame(self::FIXTURE_UUID, $this->row->getPayuUuid());
    }

    public function testTheEmailIsRecordedBeforeTheCreateIsSent(): void
    {
        $test = $this;
        $this->client->method('createChildMerchant')->willReturnCallback(
            function () use ($test): OnboardingApiResponse {
                $test->assertSame(OnboardingProcessorTest::NEW_EMAIL_PUBLIC, $test->currentRow()->getPayuEmail());
                throw new PayuOnboardingRequestException('timed out after PayU received it');
            }
        );

        $this->processor->process(self::SELLER_ID);

        self::assertSame(self::NEW_EMAIL, $this->row->getPayuEmail());
    }

    public function testAnAccountAlreadyHeldByAnotherSellerByMidIsNotAdopted(): void
    {
        // A hand-typed legacy mapping has MID and key but no uuid; the uuid check alone misses it.
        $this->withDraft(self::FIXTURE_EMAIL);
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->midOwner = $this->submerchant(['seller_id' => 18]);
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('07-get-child-by-uuid'));

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertNull($this->row->getPayuUuid());
    }

    public function testAListingShorterThanItsOwnTotalIsNeverTreatedAsNotFound(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        $listing['total_childs'] = 500;
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    /**
     * Wave-9 integrity L-2: every decision matches on email, so a listed child without one could be this
     * vendor's lost create — a retry, never a create.
     */
    public function testAListedChildWithoutAnEmailIsNeverTreatedAsNotFound(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        $listing['child_merchants']['product_accounts'][0]['email'] = '';
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    /**
     * Wave-13 integrity Low: one uuid, one account — two rows under one uuid make every skip/match ambiguous.
     */
    public function testAListingWithADuplicatedAccountIdIsNeverTreatedAsNotFound(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        $listing['child_merchants']['product_accounts'][1]['uuid'] = strtoupper((string)$listing['child_merchants']['product_accounts'][0]['uuid']);
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    public function testAListingWithoutItsRowsIsNeverTreatedAsNotFound(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 3], ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    /**
     * UAT always sends total_childs; if it ever goes missing we cannot tell a partial list from a whole one.
     */
    public function testAListingWithoutItsTotalIsNeverTreatedAsNotFound(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        unset($listing['total_childs']);
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    /**
     * Wave-2 N2: a brand-new production parent has no children, and PayU may say so without a
     * product_accounts array at all. The first vendor must still be onboarded.
     *
     * @dataProvider emptyListings
     */
    public function testAnEmptyParentStillOnboardsItsFirstVendor(array $listing): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $client->expects(self::once())->method('createChildMerchant')->willReturn($this->fixture('02-create-child'));
        $client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_AWAITING_ESIGN, $this->processor->process(self::SELLER_ID));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function emptyListings(): array
    {
        return [
            'no child_merchants' => [['total_childs' => 0]],
            'null product_accounts' => [['child_merchants' => ['product_accounts' => null], 'total_childs' => 0]],
            'empty product_accounts' => [['child_merchants' => ['product_accounts' => []], 'total_childs' => 0]],
        ];
    }

    /**
     * After a create was sent, an empty listing contradicts our own record: PayU holds at least
     * that child. Creating again on it could make a second account, so it is a retry.
     */
    public function testAnEmptyListingAfterACreateWasSentIsNotTrusted(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 0], ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    public function testALostLeaseStopsTheRunBeforeTheCreate(): void
    {
        $claim = $this->createMock(OnboardingClaim::class);
        $claim->method('claim')->willReturn(true);
        $claim->method('renew')->willReturn(false);
        $claim->expects(self::never())->method('release');
        $this->swapClaim($claim);
        $this->client->expects(self::never())->method('createChildMerchant');

        self::assertSame(OnboardingProcessor::OUTCOME_LOCKED, $this->processor->process(self::SELLER_ID));
        self::assertSame(Submerchant::STATE_QUEUED, $this->row->getOnboardingState());
    }

    public function testALostLeaseStopsTheRunBeforeTheBankIsSent(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_CREATED, 'payu_uuid' => self::FIXTURE_UUID]);
        $claim = $this->createMock(OnboardingClaim::class);
        $claim->method('claim')->willReturn(true);
        $claim->method('renew')->willReturn(false);
        $this->swapClaim($claim);
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->expects(self::never())->method('attachBankDetail');

        self::assertSame(OnboardingProcessor::OUTCOME_LOCKED, $this->processor->process(self::SELLER_ID));
    }

    public function testAHandTypedLegacyRowIsNeverWorked(): void
    {
        $this->row->setData('payu_merchant_key', 'CHLD01');
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->client->expects(self::never())->method('createChildMerchant');

        self::assertSame(OnboardingProcessor::OUTCOME_NOTHING_TO_DO, $this->processor->process(self::SELLER_ID));
    }

    public function testABankPayuRejectedIsNotReportedAsAttached(): void
    {
        $this->row->addData(['onboarding_state' => Submerchant::STATE_CREATED, 'payu_uuid' => self::FIXTURE_UUID]);
        $read = $this->fixture('07-get-child-by-uuid')->body();
        $read['product_account']['bank_detail']['bank_verification_status'] = 'Failed';
        $this->client->method('fetchChildMerchant')->willReturn(new OnboardingApiResponse(200, $read, ''));
        $this->client->expects(self::never())->method('attachBankDetail');

        $this->processor->process(self::SELLER_ID);

        self::assertSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState());
        self::assertStringContainsString('rejected the settlement bank', (string)$this->row->getLastError());
    }

    public function testWrongCredentialsPauseTheScanInsteadOfFailingTheVendor(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(401, ['status' => 'Unauthorized'], ''));
        $this->swapClient($client);
        $this->alerter->expects(self::once())->method('alert');

        self::assertSame(OnboardingProcessor::OUTCOME_PAUSED, $this->processor->process(self::SELLER_ID));
        self::assertSame(Submerchant::STATE_QUEUED, $this->row->getOnboardingState(), 'the vendor is not failed');
        self::assertSame(Submerchant::ERROR_CONFIGURATION, $this->row->getErrorClass());
        self::assertTrue($this->processor->isPaused());

        // While paused, the scan leaves everyone alone; the CLI can still force a run.
        self::assertSame(OnboardingProcessor::OUTCOME_PAUSED, $this->processor->process(self::SELLER_ID));
    }

    /**
     * Wave-2 N1: the realistic bad-credentials case fails at the TOKEN, before any route answers 401.
     */
    public function testARefusedTokenPausesTheScanToo(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willThrowException(
            new PayuOnboardingCredentialsException('PayU refused the onboarding credentials (HTTP 401).')
        );
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);
        $this->alerter->expects(self::once())->method('alert')->with(
            self::SELLER_ID,
            self::anything(),
            self::stringContains('paused'),
            self::stringContains('--seller='),
            true
        );

        self::assertSame(OnboardingProcessor::OUTCOME_PAUSED, $this->processor->process(self::SELLER_ID));
        self::assertSame(Submerchant::STATE_QUEUED, $this->row->getOnboardingState());
        self::assertSame(0, $this->row->getAttempts(), 'a credentials pause is not a vendor retry');
        self::assertTrue($this->processor->isPaused());
    }

    public function testAForcedRunThatReachesPayuLiftsThePause(): void
    {
        $this->pauseFlag = gmdate('Y-m-d H:i:s', self::NOW_TS + 1800);
        self::assertTrue($this->processor->isPaused());
        $this->client->method('createChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));

        self::assertSame(
            OnboardingProcessor::OUTCOME_AWAITING_ESIGN,
            $this->processor->process(self::SELLER_ID, true)
        );
        self::assertFalse($this->processor->isPaused(), 'PayU took our credentials, so the scan may resume');
    }

    /**
     * Wave-3 I-F2: on a parent with no children, a create PayU REFUSED must not leave the vendor
     * marked "create sent" — else every retry distrusts the empty listing and the vendor is stuck.
     */
    public function testARefusedCreateOnAnEmptyParentDoesNotStrandTheVendor(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 0], ''));
        $client->expects(self::exactly(2))->method('createChildMerchant')->willReturnOnConsecutiveCalls(
            new OnboardingApiResponse(422, ['errors' => ['pancard_number' => ['invalid']]], ''),
            $this->fixture('02-create-child')
        );
        $client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_FAILED, $this->processor->process(self::SELLER_ID));
        self::assertNull($this->row->getPayuEmail(), 'PayU said no, so no create is outstanding');

        // Staff fix the data and press Retry: the empty listing is now trusted and the create goes out.
        $this->row->setData('onboarding_state', Submerchant::STATE_QUEUED);
        self::assertSame(OnboardingProcessor::OUTCOME_AWAITING_ESIGN, $this->processor->process(self::SELLER_ID, true));
    }

    public function testATokenRefusedBeforeTheCreateLeavesNoCreateOutstanding(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 0], ''));
        $client->method('createChildMerchant')->willThrowException(
            new PayuOnboardingCredentialsException('PayU issued no access_token for scope "refer_child_merchant" (HTTP 401).')
        );
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_PAUSED, $this->processor->process(self::SELLER_ID));
        self::assertNull($this->row->getPayuEmail());
    }

    /**
     * Wave-4 integrity F1: a rate-limited token means the create never left.
     */
    public function testACreateThatNeverLeftLeavesNoCreateOutstanding(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 0], ''));
        $client->method('createChildMerchant')->willThrowException(
            new PayuOnboardingNotSentException('PayU issued no access_token for scope "refer_child_merchant" (HTTP 429).')
        );
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
        self::assertNull($this->row->getPayuEmail());
    }

    /**
     * Wave-4 correctness F2: a refusal of THIS create must not erase the mark of an EARLIER create
     * whose answer was lost — its email is the only way to adopt that account.
     */
    public function testARefusalKeepsTheMarkOfAnEarlierOutstandingCreate(): void
    {
        $this->row->setData('payu_email', 'earlier-create@example.invalid');
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));
        $client->method('createChildMerchant')->willReturn(
            new OnboardingApiResponse(422, ['errors' => ['email' => ['already exists']]], '')
        );
        $this->swapClient($client);

        $this->processor->process(self::SELLER_ID);

        self::assertSame('earlier-create@example.invalid', $this->row->getPayuEmail());
    }

    public function testATimeoutAnswerKeepsTheCreateOutstanding(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 0], ''));
        $client->method('createChildMerchant')->willReturn(new OnboardingApiResponse(408, [], ''));
        $this->swapClient($client);

        self::assertSame(
            OnboardingProcessor::OUTCOME_RETRY_SCHEDULED,
            $this->processor->process(self::SELLER_ID),
            '408 is not a refusal: retry later, do not fail the vendor'
        );
        self::assertSame(self::NEW_EMAIL, $this->row->getPayuEmail(), '408: PayU may have acted');
    }

    public function testATransportFailureOnTheCreateKeepsItOutstanding(): void
    {
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, ['total_childs' => 0], ''));
        $client->method('createChildMerchant')->willThrowException(
            new PayuOnboardingRequestException('PayU onboarding "create-child-merchant" failed at transport level.')
        );
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
        self::assertSame(self::NEW_EMAIL, $this->row->getPayuEmail(), 'the create may have landed: adopt, never re-create');
    }

    /**
     * Wave-5 integrity F1: a stale mark (an older email) must not switch off the current-email check.
     */
    public function testAStaleMarkDoesNotHideAnAccountUnderTheCurrentEmail(): void
    {
        $this->row->setData('payu_email', 'old-address@example.invalid');
        $this->withDraft(self::FIXTURE_EMAIL); // the listing fixture holds a child with this email
        $this->client->expects(self::never())->method('createChildMerchant');

        self::assertSame(OnboardingProcessor::OUTCOME_FAILED, $this->processor->process(self::SELLER_ID));
        self::assertStringContainsString('never created', (string)$this->row->getLastError());
    }

    /**
     * Wave-5 integrity F2: if another vendor's create was sent with the same email, the matching child
     * could be theirs — never adopt it automatically.
     */
    public function testAMarkAnotherVendorAlsoHoldsIsNotAdopted(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->otherRowsWithMark = 1;
        $this->client->expects(self::never())->method('fetchChildMerchant');

        self::assertSame(OnboardingProcessor::OUTCOME_FAILED, $this->processor->process(self::SELLER_ID));
        self::assertStringContainsString('seller #77', (string)$this->row->getLastError());
        self::assertNull($this->row->getPayuUuid());
    }

    /**
     * Wave-9 integrity M-1: another vendor whose create went out with the same email and who was then
     * linked by hand to a DIFFERENT account still claims the child under the mark — its own lost create
     * may be that child. Holding some account does not settle the mark; adopting would give this vendor
     * an account PayU verified with the other's PAN.
     */
    public function testAClaimantLinkedToADifferentAccountStillBlocksAdoption(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->otherRowsWithMark = 1;
        $this->otherMarkUuid = '0000-0000-00000003-0000-000000000003'; // listed, but under another email
        $this->client->expects(self::never())->method('fetchChildMerchant');

        self::assertSame(OnboardingProcessor::OUTCOME_FAILED, $this->processor->process(self::SELLER_ID));
        self::assertStringContainsString('seller #77', (string)$this->row->getLastError());
        self::assertNull($this->row->getPayuUuid());
    }

    /**
     * Wave-6 integrity F3: a child that matches the mark but is already stored on another row is that
     * row's; the mark is dead and the vendor moves on (here: nothing under their current email → create).
     */
    public function testAMarkMatchingAChildAnotherRowHoldsDoesNotStrandTheVendor(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->row->setData('last_attempt_at', gmdate('Y-m-d H:i:s', self::NOW_TS - Submerchant::CREATE_SETTLE_SECONDS));
        $this->childOwnedElsewhere = 1;
        $this->client->expects(self::once())->method('createChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));

        $this->processor->process(self::SELLER_ID);

        self::assertNotSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState(), (string)$this->row->getLastError());
    }

    /**
     * Wave-10 integrity M-2: an unanswered create may not be listed yet. Within the hour, "no match" is
     * not proof that nothing was made — a retry, never a second create. Staff Retry cannot skip it.
     *
     * @dataProvider recentOrUnknownAttempt
     */
    public function testNoSecondCreateWhileAnUnansweredOneMayStillBeListed(?int $secondsAgo): void
    {
        $this->row->setData('payu_email', 'unanswered@example.invalid');
        $this->row->setData('last_attempt_at', $secondsAgo === null ? null : gmdate('Y-m-d H:i:s', self::NOW_TS - $secondsAgo));
        $this->client->expects(self::never())->method('createChildMerchant');

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
        self::assertSame('unanswered@example.invalid', $this->row->getPayuEmail(), 'the mark is kept for adoption');
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function recentOrUnknownAttempt(): array
    {
        return ['a minute ago' => [60], 'just inside the hour' => [Submerchant::CREATE_SETTLE_SECONDS - 1], 'unknown' => [null]];
    }

    /**
     * Wave-7 integrity F1: an account under the vendor's email that ANOTHER seller already holds is
     * theirs (they changed email since); it must not stop this vendor being onboarded.
     */
    public function testAnAccountAnotherSellerHoldsDoesNotBlockANewVendorWithThatEmail(): void
    {
        $this->withDraft(self::FIXTURE_EMAIL);
        $this->childOwnedElsewhere = 1;
        $this->client->expects(self::once())->method('createChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('fetchChildMerchant')->willReturn($this->fixture('02-create-child'));
        $this->client->method('attachBankDetail')->willReturn($this->fixture('03-attach-bank'));

        $this->processor->process(self::SELLER_ID);

        self::assertNotSame(Submerchant::STATE_FAILED, $this->row->getOnboardingState(), (string)$this->row->getLastError());
    }

    /**
     * Wave-8 integrity M-2: the create gate reads the listing with the same "every row accounted for"
     * rule as staff actions — a row the parser drops (no uuid) could be this vendor's new child.
     */
    public function testAListingRowWithoutAnAccountIdStopsTheCreate(): void
    {
        $listing = $this->fixture('05-list-sub-accounts')->body();
        unset($listing['child_merchants']['product_accounts'][2]['uuid']);
        $client = $this->createMock(PayuOnboardingClient::class);
        $client->method('listSubAccounts')->willReturn(new OnboardingApiResponse(200, $listing, ''));
        $client->expects(self::never())->method('createChildMerchant');
        $this->swapClient($client);

        self::assertSame(OnboardingProcessor::OUTCOME_RETRY_SCHEDULED, $this->processor->process(self::SELLER_ID));
    }

    public function testPayuErrorTextIsRedactedBeforeItIsStoredOrShown(): void
    {
        $this->client->method('createChildMerchant')->willReturn(new OnboardingApiResponse(
            422,
            ['errors' => [
                'pancard_number' => ['ABCDE1234F is invalid', 'also abcde9999z'],
                'bank' => ['50100000000002 not found', 'account_no_123456789012 rejected'],
            ]],
            ''
        ));

        $this->processor->process(self::SELLER_ID);

        $error = (string)$this->row->getLastError();
        self::assertStringNotContainsString('ABCDE1234F', $error);
        self::assertStringNotContainsString('abcde9999z', $error, 'PAN in lower case');
        self::assertStringNotContainsString('123456789012', $error, 'digits glued to an underscore');
        self::assertStringNotContainsString('50100000000002', $error);
        self::assertStringContainsString('[PAN]', $error);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param string $email
     * @param string $pan
     * @return void
     */
    private function withDraft(string $email, string $pan = 'AAAPA0000A'): void
    {
        $this->draftEmail = $email;
        $this->draftPan = $pan;
    }

    /**
     * @return ChildMerchantDraft
     */
    public function currentDraft(): ChildMerchantDraft
    {
        return new ChildMerchantDraft(
            'Test Vendor',
            $this->draftEmail,
            '9000000001',
            'Test Ortho Supplies',
            2,
            $this->draftPan,
            'Test Vendor',
            60000
        );
    }

    private string $draftEmail = self::NEW_EMAIL;
    private string $draftPan = 'AAAPA0000A';

    /**
     * PHPUnit stubs cannot be re-stubbed, so a test that needs a different draft or bank
     * builds a fresh reader and a fresh processor around it.
     *
     * @param SettlementBankDetail $bank
     * @return VendorOnboardingDataReader&MockObject
     */
    private function rebuildReaderWithBank(SettlementBankDetail $bank): VendorOnboardingDataReader&MockObject
    {
        $reader = $this->createMock(VendorOnboardingDataReader::class);
        $reader->method('isApprovedSeller')->willReturn(true);
        $reader->method('hasAffiliateRecord')->willReturn(false);
        $reader->method('readLegalType')->willReturn('proprietorship');
        $reader->method('readShopName')->willReturn('Test Ortho Supplies');
        $reader->method('readBank')->willReturn($bank);
        $reader->method('readDraft')->willReturnCallback(fn (): ChildMerchantDraft => $this->currentDraft());
        $this->swapReader($reader);
        return $reader;
    }

    private function swapReader(VendorOnboardingDataReader $reader): void
    {
        $this->rebuild(reader: $reader);
    }

    private function swapClient(PayuOnboardingClient $client): void
    {
        $this->rebuild(client: $client);
    }

    private function swapClaim(OnboardingClaim $claim): void
    {
        $this->rebuild(claim: $claim);
    }

    private function swapConfig(OnboardingConfig $config): void
    {
        $this->rebuild(config: $config);
    }

    private function rebuild(
        ?OnboardingConfig $config = null,
        ?OnboardingClaim $claim = null,
        ?VendorOnboardingDataReader $reader = null,
        ?PayuOnboardingClient $client = null
    ): void {
        $this->config = $config ?? $this->config;
        $this->claim = $claim ?? $this->claim;
        $this->reader = $reader ?? $this->reader;
        $this->client = $client ?? $this->client;
        $this->build();
    }

    /**
     * @param array<string, mixed> $data
     * @return Submerchant
     */
    private function submerchant(array $data): Submerchant
    {
        $model = $this->getMockBuilder(Submerchant::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $model->setIdFieldName('entity_id');
        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }
        return $model;
    }

    /**
     * @param string $name
     * @return OnboardingApiResponse
     */
    private function fixture(string $name): OnboardingApiResponse
    {
        $json = new Json();
        /** @var array<string, mixed> $recorded */
        $recorded = $json->unserialize((string)file_get_contents(__DIR__ . '/../_fixtures/onboarding/' . $name . '.json'));
        $body = is_array($recorded['body'] ?? null) ? $recorded['body'] : [];
        return new OnboardingApiResponse((int)$recorded['http'], $body, $json->serialize($body));
    }
}
