<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Service\CreateMarkClaims;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

/**
 * Wave-9 integrity M-1: a create mark is a live claim until the row's OWN account is the child under it.
 */
class CreateMarkClaimsTest extends TestCase
{
    private const MARK = 'shared@example.invalid';

    /** @var array<int, array{uuid: string, email: string}> */
    private const LISTING = [
        ['uuid' => 'u-under-mark', 'email' => 'SHARED@example.invalid '],
        ['uuid' => 'u-elsewhere', 'email' => 'other@example.invalid'],
    ];

    /** @var array<int, array{0: mixed, 1: mixed}> */
    private array $filters = [];

    public function testARowWithNoAccountIsALiveClaimant(): void
    {
        self::assertSame([18], $this->claims([['seller_id' => 18, 'payu_uuid' => null]]));
    }

    public function testARowWhoseOwnAccountIsTheChildUnderTheMarkIsNot(): void
    {
        self::assertSame([], $this->claims([['seller_id' => 18, 'payu_uuid' => 'U-UNDER-MARK']]));
    }

    public function testARowHoldingADifferentAccountStillClaims(): void
    {
        self::assertSame([18], $this->claims([['seller_id' => 18, 'payu_uuid' => 'u-elsewhere']]));
    }

    public function testARowWhoseAccountPayuDoesNotListStillClaims(): void
    {
        self::assertSame([18], $this->claims([['seller_id' => 18, 'payu_uuid' => 'u-gone']]));
    }

    public function testTheAskingRowIsExcludedAndAnEmptyEmailClaimsNothing(): void
    {
        $this->claims([]);
        self::assertContains(['seller_id', ['neq' => 42]], $this->filters);
        self::assertContains(['payu_email', self::MARK], $this->filters);

        $this->filters = [];
        self::assertSame([], (new CreateMarkClaims($this->factory([])))->otherClaimants(42, '  ', self::LISTING));
        self::assertSame([], $this->filters, 'no query for an empty email');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function claims(array $rows): array
    {
        $rows = array_map(fn (array $r): DataObject => new DataObject($r + ['payu_email' => self::MARK]), $rows);
        return (new CreateMarkClaims($this->factory($rows)))->otherClaimants(42, ' ' . self::MARK, self::LISTING);
    }

    /**
     * @param DataObject[] $rows
     */
    private function factory(array $rows): CollectionFactory
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use (&$collection) {
            $this->filters[] = [$field, $condition];
            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }
}
