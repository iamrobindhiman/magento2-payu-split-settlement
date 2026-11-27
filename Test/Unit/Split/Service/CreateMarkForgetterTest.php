<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Gateway\PayuOnboardingClient;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\CreateMarkClaims;
use RKD\PayuSplit\Split\Service\CreateMarkForgetter;
use RKD\PayuSplit\Split\Service\ExistingChildFinder;
use RKD\PayuSplit\Split\Service\OnboardingResponseParser;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Forget create mark" (wave-6 integrity F3, wave-7 F3/F4 + security L-2). Clearing a mark on a vendor
 * with no account lets onboarding create one — so it must refuse whenever PayU holds an account the
 * mark could belong to.
 */
class CreateMarkForgetterTest extends TestCase
{
    private const FIXTURE_EMAIL = 's2-fixture-260910144139@example.invalid';

    private Submerchant $row;
    private PayuOnboardingClient&MockObject $client;
    private SettlementAuditLogger&MockObject $audit;
    private int $sharedMark = 0;
    private int $heldElsewhere = 0;
    private AdapterInterface&MockObject $connection;
    /** @var array<int|string, mixed>|null */
    private ?array $casWhere = null;

    protected function setUp(): void
    {
        $this->row = $this->submerchant([
            'entity_id' => 9, 'seller_id' => 37, 'onboarding_state' => Submerchant::STATE_FAILED,
            'payu_email' => 'stale@example.invalid', 'last_attempt_at' => '2026-09-24 08:59:59',
        ]);
        $this->client = $this->createMock(PayuOnboardingClient::class);
        $this->client->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));
        $this->audit = $this->createMock(SettlementAuditLogger::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('update')->willReturnCallback(function ($table, $bind, $where): int {
            $this->casWhere = $where;
            return 1;
        });
    }

    public function testAMarkWithNoAccountAtPayuIsClearedAndAuditedMasked(): void
    {
        $this->audit->expects(self::once())->method('record')->with(
            AuditLog::ACTION_FORGET_MARK,
            AuditLog::ENTITY_SUBMERCHANT_ROW,
            9,
            null,
            Submerchant::STATE_FAILED,
            Submerchant::STATE_FAILED,
            self::logicalAnd(self::stringContains('s***@example.invalid'), self::logicalNot(self::stringContains('stale@')))
        );

        $this->forgetter()->forget(37);

        self::assertSame('stale@example.invalid', $this->casWhere['payu_email = ?'] ?? null, 'only the mark that was checked');
        self::assertSame(Submerchant::STATE_FAILED, $this->casWhere['onboarding_state = ?'] ?? null);
        self::assertArrayHasKey('payu_uuid IS NULL', $this->casWhere);
    }

    /**
     * Wave-8 integrity L-4: a Retry + new create landing while PayU was being read → nothing written.
     */
    public function testARowThatChangedWhilePayuWasReadIsLeftAlone(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willReturn(0);
        $this->connection = $connection;
        $this->audit->expects(self::never())->method('record');

        $this->expectExceptionMessageMatches('/changed while/');
        $this->forgetter()->forget(37);
    }

    public function testAMarkMatchingAnAccountNobodyHoldsIsKeptForRetryToAdopt(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->audit->expects(self::never())->method('record');

        $this->expectExceptionMessageMatches('/press Retry/');
        $this->forgetter()->forget(37);
    }

    public function testAMarkMatchingAnAccountAnotherRowHoldsMayBeCleared(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->heldElsewhere = 1;

        $this->forgetter()->forget(37);

        self::assertSame(self::FIXTURE_EMAIL, $this->casWhere['payu_email = ?'] ?? null);
    }

    /**
     * Wave-8 integrity M-1: another row sharing the mark is NOT a reason to skip PayU — a child nobody
     * holds may be THIS vendor's lost create, and clearing its only adoption path creates a second one.
     */
    public function testASharedMarkStillAsksPayuAndKeepsAnUnheldAccountAdoptable(): void
    {
        $this->row->setData('payu_email', self::FIXTURE_EMAIL);
        $this->sharedMark = 1;
        $this->client->expects(self::once())->method('listSubAccounts')->willReturn($this->fixture('05-list-sub-accounts'));
        $this->audit->expects(self::never())->method('record');

        $this->expectExceptionMessageMatches('/no other vendor holds it/');
        $this->forgetter()->forget(37);
    }

    public function testARowWhoseOwnAccountIsTheChildUnderItsMarkDropsTheDeadMark(): void
    {
        $this->row->addData([
            'onboarding_state' => Submerchant::STATE_AWAITING_ESIGN,
            'payu_uuid' => '0000-0000-00000001-0000-000000000001',
            'payu_email' => self::FIXTURE_EMAIL,
            'last_attempt_at' => '2026-09-24 09:59:00', // no window: its create was answered
        ]);
        $this->connection->expects(self::once())->method('commit');

        $this->forgetter()->forget(37);

        self::assertSame('0000-0000-00000001-0000-000000000001', $this->casWhere['payu_uuid = ?'] ?? null);
    }

    /**
     * Wave-9 integrity M-1: a row linked by hand to a DIFFERENT account while a child nobody holds sits
     * under its mark — that child may be its lost create, and the mark is what stops another vendor
     * adopting an account PayU verified with this vendor's PAN. Never cleared without a person linking.
     */
    public function testARowLinkedElsewhereKeepsAMarkThatStillClaimsAnUnheldChild(): void
    {
        $this->row->addData([
            'onboarding_state' => Submerchant::STATE_AWAITING_ESIGN,
            'payu_uuid' => '0000-0000-00000003-0000-000000000003',
            'payu_email' => self::FIXTURE_EMAIL,
        ]);
        $this->audit->expects(self::never())->method('record');
        $this->connection->expects(self::never())->method('update');

        $this->expectExceptionMessageMatches('/linked to a different account/');
        $this->forgetter()->forget(37);
    }

    /**
     * Wave-9 integrity M-2: a create PayU completed may not be listed yet. Inside the hour Retry already
     * waits for, Forget refuses before PayU is even asked — else Retry creates a second child.
     */
    public function testAFreshAttemptIsNotForgottenBeforeTheListingSettles(): void
    {
        $this->row->setData('last_attempt_at', '2026-09-24 09:00:01');
        $this->client->expects(self::never())->method('listSubAccounts');
        $this->audit->expects(self::never())->method('record');

        $this->expectExceptionMessageMatches('/under an hour ago/');
        $this->forgetter()->forget(37);
    }

    public function testAnUnknownAttemptTimeIsTreatedAsFresh(): void
    {
        $this->row->setData('last_attempt_at', null);
        $this->client->expects(self::never())->method('listSubAccounts');

        $this->expectExceptionMessageMatches('/under an hour ago/');
        $this->forgetter()->forget(37);
    }

    /**
     * Wave-9 security L-3: PayU is read before any transaction opens.
     */
    public function testPayuIsReadBeforeTheTransactionOpens(): void
    {
        $order = [];
        $this->client = $this->createMock(PayuOnboardingClient::class);
        $this->client->method('listSubAccounts')->willReturnCallback(function () use (&$order) {
            $order[] = 'read';
            return $this->fixture('05-list-sub-accounts');
        });
        $this->connection->method('beginTransaction')->willReturnCallback(function () use (&$order) {
            $order[] = 'begin';
            return $this->connection;
        });

        $this->forgetter()->forget(37);

        self::assertSame(['read', 'begin'], $order);
    }

    /**
     * @dataProvider notEligible
     * @param array<string, mixed> $data
     */
    public function testRowsInOnboardingsHandsAreLeftAlone(array $data): void
    {
        $this->row->addData($data);
        $this->expectException(LocalizedException::class);
        $this->forgetter()->forget(37);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function notEligible(): array
    {
        return [
            'queued' => [['onboarding_state' => Submerchant::STATE_QUEUED]],
            'created (worker holds it)' => [['onboarding_state' => Submerchant::STATE_CREATED, 'payu_uuid' => 'u-1']],
            'no mark' => [['payu_email' => null]],
        ];
    }

    private function forgetter(): CreateMarkForgetter
    {
        $repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $repository->method('getBySellerId')->willReturn($this->row);
        $collections = $this->createMock(CollectionFactory::class);
        $collections->method('create')->willReturnCallback(function () {
            $asked = [];
            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $condition) use (&$asked, &$collection) {
                    $asked[is_array($field) ? implode('|', $field) : $field] = $condition;
                    return $collection;
                }
            );
            $collection->method('getSize')->willReturnCallback(function () use (&$asked): int {
                if (($asked['seller_id'] ?? null) !== ['neq' => 37]) {
                    return 1;
                }
                return isset($asked['payu_email']) ? $this->sharedMark : $this->heldElsewhere;
            });
            return $collection;
        });

        $resource = $this->createMock(SubmerchantResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('rkd_payu_submerchant');

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn((int)strtotime('2026-09-24 10:00:00 UTC'));

        return new CreateMarkForgetter(
            $repository,
            $resource,
            $collections,
            new ExistingChildFinder($this->client, new OnboardingResponseParser(), $collections, new CreateMarkClaims($collections)),
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
