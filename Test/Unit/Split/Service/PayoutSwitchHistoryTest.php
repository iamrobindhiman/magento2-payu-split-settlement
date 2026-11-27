<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog\CollectionFactory;
use RKD\PayuSplit\Split\Service\PayoutSwitchHistory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

/**
 * The Deactivate/Reactivate history belongs to the mapping ROW (wave-5 integrity F3).
 */
class PayoutSwitchHistoryTest extends TestCase
{
    /** @var array<int, array{0: mixed, 1: mixed}> */
    private array $filters = [];
    /** @var array<int, array{0: string, 1: string}> */
    private array $orders = [];

    public function testItAsksForTheLatestSwitchOnThisRowOnly(): void
    {
        $this->history(AuditLog::ACTION_DEACTIVATE)->isSwitchedOffByStaff(5);

        self::assertContains(['entity_type', AuditLog::ENTITY_SUBMERCHANT_ROW], $this->filters);
        self::assertContains(['entity_ref_id', 5], $this->filters);
        self::assertContains(
            ['action', ['in' => [
                AuditLog::ACTION_DEACTIVATE,
                AuditLog::ACTION_REACTIVATE,
                AuditLog::ACTION_LEGACY_RESET,
                AuditLog::ACTION_ONBOARDING_LINK,
            ]]],
            $this->filters
        );
        self::assertSame([['entity_id', 'DESC']], $this->orders, 'latest first');
    }

    public function testTheLatestSwitchDecides(): void
    {
        self::assertTrue($this->history(AuditLog::ACTION_DEACTIVATE)->isSwitchedOffByStaff(5));
        self::assertFalse($this->history(AuditLog::ACTION_REACTIVATE)->isSwitchedOffByStaff(5));
        self::assertFalse($this->history(null)->isSwitchedOffByStaff(5), 'never switched');
        self::assertFalse(
            $this->history(AuditLog::ACTION_LEGACY_RESET)->isSwitchedOffByStaff(5),
            'a reset ends the old history: its Deactivate no longer unlocks Reactivate'
        );
    }

    private function history(?string $latestAction): PayoutSwitchHistory
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) use (&$collection) {
            $this->filters[] = [$field, $condition];
            return $collection;
        });
        $collection->method('setOrder')->willReturnCallback(function ($field, $direction) use (&$collection) {
            $this->orders[] = [$field, $direction];
            return $collection;
        });
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(new DataObject(['action' => $latestAction]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return new PayoutSwitchHistory($factory);
    }
}
