<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Form;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Form\SubmerchantItemConvertor;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\PayoutSwitchHistory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Server-side validation for the submerchant CRUD form. The Loki SaveAction maps POST values
 * onto the model and then calls beforeSave() right before persisting — this is the only point
 * at which a bad money-routing row can be refused. The convertor trims the child merchant key
 * (killing the stored-key-vs-config whitespace/case mismatch flagged in the A5b money review),
 * requires a key + a positive seller, constrains the KYC status to the known set, and
 * normalises the active flag. Pure — operates on a DataObject, no DB.
 */
class SubmerchantItemConvertorTest extends TestCase
{
    private SubmerchantItemConvertor $convertor;
    private SubmerchantRepositoryInterface&MockObject $repository;

    private int $otherRowsWithRouting = 0;

    private bool $switchedOffByStaff = false;
    /** @var array<int, array{0: mixed, 1: mixed}> */
    private array $routingFilters = [];

    private function history(): PayoutSwitchHistory
    {
        $history = $this->createMock(PayoutSwitchHistory::class);
        $history->method('isSwitchedOffByStaff')->willReturnCallback(fn (): bool => $this->switchedOffByStaff);
        return $history;
    }

    private function collections(): CollectionFactory
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use (&$collection) {
            $this->routingFilters[] = [$field, $condition];
            return $collection;
        });
        $collection->method('getSize')->willReturnCallback(fn (): int => $this->otherRowsWithRouting);
        $collection->method('getFirstItem')->willReturn(new DataObject(['seller_id' => 20]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    public function testAKeyAlreadyMappedToAnotherSellerIsRefused(): void
    {
        $this->otherRowsWithRouting = 1;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/already mapped to seller 20/');
        $this->convertor->beforeSave($this->row());
    }

    /**
     * Wave-4 correctness F4: a row being moved to another seller must not collide with its own key.
     */
    public function testTheRoutingCheckExcludesThisRowByIdNotBySeller(): void
    {
        $this->repository->method('getById')->willReturn(null);
        $this->convertor->beforeSave($this->row(['entity_id' => 7, 'seller_id' => 99]));

        self::assertContains(['entity_id', ['neq' => 7]], $this->routingFilters);
        self::assertNotContains(['seller_id', ['neq' => 99]], $this->routingFilters);
    }

    /**
     * Wave-4 correctness F5: a form opened before "Deactivate payouts" must not silently undo it.
     */
    public function testAFormCannotUndoADeactivate(): void
    {
        $this->repository->method('getById')->willReturn($this->switchedOffRow());
        $this->switchedOffByStaff = true;

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Use "Reactivate payouts"/');
        $this->convertor->beforeSave($this->row(['entity_id' => 7, 'is_active' => '1']));
    }

    /**
     * Wave-9 integrity L-1: "Reactivate payouts" switches on whatever the row holds, so while staff have it
     * switched off the form cannot change the account or seller it was switched off on.
     *
     * @dataProvider switchedOffEdits
     * @param array<string, mixed> $edit
     */
    public function testASwitchedOffMappingKeepsItsAccountAndSeller(array $edit): void
    {
        $this->repository->method('getById')->willReturn($this->switchedOffRow());
        $this->switchedOffByStaff = true;

        $this->expectExceptionMessageMatches('/frozen until "Reactivate payouts"/');
        $this->convertor->beforeSave($this->row(array_merge(['entity_id' => 7, 'is_active' => '0'], $edit)));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function switchedOffEdits(): array
    {
        return [
            'key' => [['payu_merchant_key' => 'NEWKEY']],
            'mid' => [['payu_mid' => '9000002']],
            'seller' => [['seller_id' => 19]],
        ];
    }

    public function testASwitchedOffMappingMayStillBeSavedUnchanged(): void
    {
        $this->repository->method('getById')->willReturn($this->switchedOffRow());
        $this->switchedOffByStaff = true;

        $saved = $this->convertor->beforeSave($this->row(['entity_id' => 7, 'is_active' => '0', 'kyc_status' => 'pending']));

        self::assertSame('pending', $saved->getData('kyc_status'));
    }

    private function switchedOffRow(): Submerchant&MockObject
    {
        $stored = $this->createMock(Submerchant::class);
        $stored->method('isActive')->willReturn(false);
        $stored->method('isManagedByOnboarding')->willReturn(false);
        $stored->method('getSellerId')->willReturn(18);
        $stored->method('getMerchantKey')->willReturn('CHLD01');
        $stored->method('getPayuMid')->willReturn(null);
        return $stored;
    }

    public function testAFormMayStillActivateALegacyRowNobodySwitchedOff(): void
    {
        $stored = $this->createMock(Submerchant::class);
        $stored->method('isActive')->willReturn(false);
        $stored->method('isManagedByOnboarding')->willReturn(false);
        $this->repository->method('getById')->willReturn($stored);

        self::assertSame(1, $this->convertor->beforeSave($this->row(['entity_id' => 7, 'is_active' => '1']))->getData('is_active'));
    }

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubmerchantRepositoryInterface::class);
        // Default: the seller has no existing mapping (the common create/edit case).
        $this->repository->method('getBySellerId')->willReturn(null);
        $this->convertor = new SubmerchantItemConvertor($this->repository, $this->collections(), $this->history());
    }

    /**
     * Build a convertor whose repository reports an existing mapping (entity_id $existingId)
     * for any seller — to exercise the duplicate guard.
     */
    private function convertorWithExistingMapping(int $existingId): SubmerchantItemConvertor
    {
        $existing = $this->createMock(Submerchant::class);
        $existing->method('getId')->willReturn($existingId);
        $repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $repository->method('getBySellerId')->willReturn($existing);
        return new SubmerchantItemConvertor($repository, $this->collections(), $this->history());
    }

    /**
     * A baseline valid row; individual tests mutate one field to isolate a single rule.
     *
     * @param array<string, mixed> $overrides
     * @return DataObject
     */
    private function row(array $overrides = []): DataObject
    {
        return new DataObject(array_merge([
            'seller_id' => 18,
            'payu_merchant_key' => 'CHLD01',
            'payu_mid' => '',
            'kyc_status' => 'verified',
            'is_active' => '1',
        ], $overrides));
    }

    public function testTrimsTheMerchantKey(): void
    {
        $item = $this->convertor->beforeSave($this->row(['payu_merchant_key' => '  CHLD01  ']));
        $this->assertSame('CHLD01', $item->getData('payu_merchant_key'));
    }

    public function testThrowsWhenMerchantKeyIsBlank(): void
    {
        $this->expectException(LocalizedException::class);
        $this->convertor->beforeSave($this->row(['payu_merchant_key' => '   ']));
    }

    public function testThrowsWhenSellerIdIsNotPositive(): void
    {
        $this->expectException(LocalizedException::class);
        $this->convertor->beforeSave($this->row(['seller_id' => 0]));
    }

    public function testNormalisesSellerIdToAnInteger(): void
    {
        // Posted as a string; the convertor must write back an int so the persisted contract
        // matches the other normalised fields.
        $item = $this->convertor->beforeSave($this->row(['seller_id' => '18']));
        $this->assertSame(18, $item->getData('seller_id'));
    }

    public function testThrowsWhenSellerIdIsAlreadyMappedToADifferentRow(): void
    {
        // Creating a second mapping for a seller that already has one (entity_id 1) must be
        // refused with a clean message rather than hitting the UNIQUE constraint as a 500.
        $convertor = $this->convertorWithExistingMapping(1);
        $this->expectException(LocalizedException::class);
        $convertor->beforeSave($this->row(['seller_id' => 18])); // new row: no entity_id
    }

    public function testAllowsSavingTheSellersOwnExistingRow(): void
    {
        // Editing the row that already owns this seller_id is fine (same entity_id).
        $convertor = $this->convertorWithExistingMapping(5);
        $item = $convertor->beforeSave($this->row(['seller_id' => 18, 'entity_id' => 5]));
        $this->assertSame('CHLD01', $item->getData('payu_merchant_key'));
    }

    public function testDefaultsKycStatusToPendingWhenEmpty(): void
    {
        $item = $this->convertor->beforeSave($this->row(['kyc_status' => '']));
        $this->assertSame('pending', $item->getData('kyc_status'));
    }

    public function testThrowsOnUnknownKycStatus(): void
    {
        $this->expectException(LocalizedException::class);
        $this->convertor->beforeSave($this->row(['kyc_status' => 'bogus']));
    }

    public function testNormalisesIsActiveToOne(): void
    {
        $item = $this->convertor->beforeSave($this->row(['is_active' => '1']));
        $this->assertSame(1, $item->getData('is_active'));
    }

    public function testNormalisesIsActiveToZero(): void
    {
        $zero = $this->convertor->beforeSave($this->row(['is_active' => '0']));
        $this->assertSame(0, $zero->getData('is_active'));

        $blank = $this->convertor->beforeSave($this->row(['is_active' => '']));
        $this->assertSame(0, $blank->getData('is_active'));
    }

    public function testTrimsMidAndNullsItWhenEmpty(): void
    {
        $blank = $this->convertor->beforeSave($this->row(['payu_mid' => '   ']));
        $this->assertNull($blank->getData('payu_mid'));

        $padded = $this->convertor->beforeSave($this->row(['payu_mid' => ' M123 ']));
        $this->assertSame('M123', $padded->getData('payu_mid'));
    }

    public function testAValidRowPassesWithNormalisedValues(): void
    {
        $item = $this->convertor->beforeSave($this->row([
            'seller_id' => 20,
            'payu_merchant_key' => ' CHILD20 ',
            'payu_mid' => ' 99001 ',
            'kyc_status' => 'pending',
            'is_active' => '1',
        ]));

        $this->assertSame(20, $item->getData('seller_id'));
        $this->assertSame('CHILD20', $item->getData('payu_merchant_key'));
        $this->assertSame('99001', $item->getData('payu_mid'));
        $this->assertSame('pending', $item->getData('kyc_status'));
        $this->assertSame(1, $item->getData('is_active'));
    }

    public function testStripsTimestampsSoTheDatabaseManagesThem(): void
    {
        // The Loki form posts every table column, including created_at / updated_at (null for a
        // new row). Persisting those would clobber the DB defaults / on-update; strip them so
        // the schema (CURRENT_TIMESTAMP + on_update) owns both.
        $item = $this->convertor->beforeSave($this->row([
            'created_at' => null,
            'updated_at' => null,
        ]));
        $this->assertFalse($item->hasData('created_at'));
        $this->assertFalse($item->hasData('updated_at'));
    }

    public function testAfterLoadReturnsTheItemUnchanged(): void
    {
        $item = $this->row();
        $this->assertSame($item, $this->convertor->afterLoad($item));
    }

    public function testAfterLoadDefaultsIsActiveToActiveForNewRows(): void
    {
        // Loki seeds a new row's smallint columns to null, so the dropdown would render
        // "Active" while the model value is null → it persists as 0. Seed it to 1 so the
        // displayed default matches what is saved. (Mirror Loki's exact null seed.)
        $item = $this->convertor->afterLoad(new DataObject(['seller_id' => 0, 'is_active' => null]));
        $this->assertSame(1, $item->getData('is_active'));
    }

    public function testAfterLoadDefaultsKycToPendingForNewRows(): void
    {
        $item = $this->convertor->afterLoad(new DataObject(['kyc_status' => '']));
        $this->assertSame('pending', $item->getData('kyc_status'));
    }

    public function testAfterLoadLeavesAnExistingInactiveRowInactive(): void
    {
        // Editing a deactivated mapping must never silently flip it back to active.
        $existing = new DataObject(['is_active' => 0, 'kyc_status' => 'verified']);
        $this->assertSame(0, $this->convertor->afterLoad($existing)->getData('is_active'));
    }

    public function testARowUnderOnboardingCannotBeChangedFromTheForm(): void
    {
        // A stale form would otherwise write back an old state, uuid or e-sign mark.
        $stored = $this->getMockBuilder(Submerchant::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $stored->setData(['entity_id' => 5, 'seller_id' => 30, 'onboarding_state' => Submerchant::STATE_AWAITING_ESIGN,
            'payu_uuid' => 'u-1']);
        $repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $repository->method('getBySellerId')->willReturn(null);
        $repository->method('getById')->with(5)->willReturn($stored);

        $this->expectException(LocalizedException::class);
        (new SubmerchantItemConvertor($repository, $this->collections(), $this->history()))->beforeSave($this->row(['entity_id' => 5, 'seller_id' => 30]));
    }

    public function testOnboardingColumnsAreNeverTakenFromThePost(): void
    {
        $this->repository->method('getById')->willReturn(null);
        $item = $this->convertor->beforeSave($this->row([
            'onboarding_state' => Submerchant::STATE_QUEUED,
            'payu_uuid' => 'typed-by-hand',
            'esign_sent_at' => '2026-01-01 00:00:00',
            'locked_until' => null,
        ]));

        foreach (Submerchant::ONBOARDING_COLUMNS as $column) {
            self::assertFalse($item->hasData($column), $column . ' must not be written from the form');
        }
    }
}
