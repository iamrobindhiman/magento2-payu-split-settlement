<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem\Collection as ItemCollection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem\CollectionFactory as ItemCollectionFactory;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Service\DeliverySegmentStamper;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Fab\Returns\Model\Config as ReturnsConfig;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The stamper is the money-adjacent write: it records each item's delivery date and the
 * derived return-window close so A5c's per-item eligibility gate can fire. Pins:
 *   - window_closes_at = delivered_at + the configured return window (paise of time, exact);
 *   - WRITE-ONCE: an item already stamped is never overwritten (a second poll is a no-op);
 *   - the segment's denormalised delivered_at/window_closes_at = max across its item rows,
 *     set only once every item is delivered (decorative for admin/fast-scan; eligibility
 *     reads the item rows directly).
 */
class DeliverySegmentStamperTest extends TestCase
{
    private ObjectManager $om;
    private $itemCollectionFactory;
    private $itemResource;
    private $segmentLoader;
    private $segmentResource;
    private $returnsConfig;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
        $this->itemCollectionFactory = $this->createMock(ItemCollectionFactory::class);
        $this->itemResource = $this->createMock(SplitSegmentItemResource::class);
        $this->segmentLoader = $this->createMock(SegmentLoader::class);
        $this->segmentResource = $this->createMock(SplitSegmentResource::class);
        $this->returnsConfig = $this->createMock(ReturnsConfig::class);

        // No-op transaction (begin/commit/rollBack) on the item resource connection.
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $this->itemResource->method('getConnection')->willReturn($connection);
    }

    private function stamper(): DeliverySegmentStamper
    {
        return new DeliverySegmentStamper(
            $this->itemCollectionFactory,
            $this->itemResource,
            $this->segmentLoader,
            $this->segmentResource,
            $this->returnsConfig
        );
    }

    /**
     * @param SplitSegmentItem[] $items
     */
    private function expectItemCollection(array $items): void
    {
        $collection = $this->createMock(ItemCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn($items);
        $this->itemCollectionFactory->method('create')->willReturn($collection);
    }

    private function item(?string $deliveredAt, ?string $windowClosesAt = null): SplitSegmentItem
    {
        /** @var SplitSegmentItem $item */
        $item = $this->om->getObject(SplitSegmentItem::class);
        $item->setData('delivered_at', $deliveredAt);
        $item->setData('window_closes_at', $windowClosesAt);
        return $item;
    }

    public function testStampsUndeliveredItemWriteOnceAndComputesWindow(): void
    {
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);

        $fresh = $this->item(null);                                  // to be stamped
        $already = $this->item('2026-06-01 00:00:00', '2026-06-08 00:00:00'); // must be left untouched
        $this->expectItemCollection([$fresh, $already]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // Only the fresh item is persisted (write-once); the already-stamped one is skipped.
        $this->itemResource->expects($this->once())->method('save')->with($fresh)->willReturnSelf();

        $this->stamper()->stamp(9, '2026-06-10 14:30:00');

        $this->assertSame('2026-06-10 14:30:00', $fresh->getDeliveredAt());
        $this->assertSame('2026-06-17 14:30:00', $fresh->getWindowClosesAt()); // +7 days
        // Untouched item keeps its original stamps.
        $this->assertSame('2026-06-01 00:00:00', $already->getDeliveredAt());
        $this->assertSame('2026-06-08 00:00:00', $already->getWindowClosesAt());
    }

    public function testRecomputesSegmentDerivedAsMaxAcrossItems(): void
    {
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);

        $fresh = $this->item(null);
        $earlier = $this->item('2026-06-01 00:00:00', '2026-06-08 00:00:00');
        $this->expectItemCollection([$fresh, $earlier]);

        /** @var SplitSegment $segment */
        $segment = $this->om->getObject(SplitSegment::class);
        $this->segmentLoader->method('loadById')->with(9)->willReturn($segment);
        $this->itemResource->method('save')->willReturnSelf();

        // Once every item is delivered, the segment's denormalised fields are the max.
        $this->segmentResource->expects($this->once())->method('save')->with($segment)->willReturnSelf();

        $this->stamper()->stamp(9, '2026-06-10 14:30:00');

        $this->assertSame('2026-06-10 14:30:00', $segment->getData('delivered_at')); // max delivered
        $this->assertSame('2026-06-17 14:30:00', $segment->getData('window_closes_at')); // max window
    }

    public function testAlreadyFullyDeliveredSegmentIsANoOp(): void
    {
        $a = $this->item('2026-06-01 00:00:00', '2026-06-08 00:00:00');
        $b = $this->item('2026-06-02 00:00:00', '2026-06-09 00:00:00');
        $this->expectItemCollection([$a, $b]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // Nothing to stamp → no item writes (a second poll after delivery is a no-op).
        $this->itemResource->expects($this->never())->method('save');

        $this->stamper()->stamp(9, '2026-06-10 14:30:00');
    }

    public function testZeroDayConfigIsFlooredToOneDaySettlementBuffer(): void
    {
        // Even if returns are disabled (window=0), the vendor's escrow is held >= 1 day past
        // delivery — never released the same instant delivery is detected (settlement safety
        // margin; prevents a misconfigured 0 from green-lighting an instant release).
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(0);

        $fresh = $this->item(null);
        $this->expectItemCollection([$fresh]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));
        $this->itemResource->method('save')->willReturnSelf();

        $this->stamper()->stamp(9, '2026-06-10 14:30:00');

        $this->assertSame('2026-06-11 14:30:00', $fresh->getWindowClosesAt()); // +1 day floor
    }
}
