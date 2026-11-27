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
 * The stamper is the money-adjacent write: it records each DELIVERED item's delivery date and the
 * derived return-window close so A5c's per-item eligibility gate can fire. Pins:
 *   - ONLY items whose sales_order_item id is in the delivered map are touched — a delivered parcel
 *     never stamps a seller's other, still-undelivered items (the F1 over-release fix);
 *   - a line is stamped delivered_at ONLY once its delivered qty reaches its ordered qty — a
 *     partial-QUANTITY shipment (ship 1 of qty 2) records the partial delivered_qty but leaves the
 *     line ungated (the F1-qty over-release fix);
 *   - delivered_qty accumulates via max() (a parcel can't un-deliver) and is idempotent across polls;
 *   - window_closes_at = delivered_at + the configured return window (exact seconds), set at full-line
 *     delivery;
 *   - WRITE-ONCE: a fully-delivered item is never overwritten (a second poll is a no-op);
 *   - the segment's denormalised delivered_at/window_closes_at = max across its item rows, set only
 *     once every item is fully delivered (decorative; eligibility reads the item rows directly).
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

    private function item(
        ?string $deliveredAt,
        ?string $windowClosesAt = null,
        int $orderItemId = 52,
        int $qty = 1,
        int $deliveredQty = 0
    ): SplitSegmentItem {
        /** @var SplitSegmentItem $item */
        $item = $this->om->getObject(SplitSegmentItem::class);
        $item->setData('order_item_id', $orderItemId);
        $item->setData('qty', $qty);
        $item->setData('delivered_qty', $deliveredQty);
        $item->setData('delivered_at', $deliveredAt);
        $item->setData('window_closes_at', $windowClosesAt);
        return $item;
    }

    public function testStampsOnlyItemsInsideADeliveredShipment(): void
    {
        // F1: item 52 is inside a delivered parcel (its full qty); item 53 is not shipped/delivered.
        // Only 52 is stamped; 53 stays undelivered, so the segment cannot yet become releasable.
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);
        $delivered = $this->item(null, null, 52, 1);
        $notDelivered = $this->item(null, null, 53, 1);
        $this->expectItemCollection([$delivered, $notDelivered]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // Only item 52 is persisted; 53 is left untouched.
        $this->itemResource->expects($this->once())->method('save')->with($delivered)->willReturnSelf();

        $this->stamper()->stampItems(9, [52 => 1], '2026-06-10 14:30:00');

        $this->assertSame('2026-06-10 14:30:00', $delivered->getDeliveredAt());
        $this->assertSame(1, $delivered->getDeliveredQty());
        $this->assertNull($notDelivered->getDeliveredAt()); // still undelivered → segment stays ungated
    }

    public function testEmptyDeliveredMapIsANoOp(): void
    {
        // Nothing delivered → no query, no writes.
        $this->itemCollectionFactory->expects($this->never())->method('create');
        $this->itemResource->expects($this->never())->method('save');

        $this->stamper()->stampItems(9, [], '2026-06-10 14:30:00');
    }

    public function testPartialQuantityRecordsDeliveredQtyButLeavesLineUngated(): void
    {
        // F1-qty CORE: order line 52 is qty 2; parcel delivers 1 unit. delivered_qty becomes 1 but
        // delivered_at stays null → the line (and thus the segment) is NOT releasable. The undelivered
        // 2nd unit's escrow can never be released off this partial delivery.
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);
        $partial = $this->item(null, null, 52, 2, 0);
        $this->expectItemCollection([$partial]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // The partial delivered_qty IS persisted so a later parcel can complete the line.
        $this->itemResource->expects($this->once())->method('save')->with($partial)->willReturnSelf();

        $this->stamper()->stampItems(9, [52 => 1], '2026-06-10 14:30:00');

        $this->assertSame(1, $partial->getDeliveredQty());
        $this->assertNull($partial->getDeliveredAt());       // NOT delivered — 1 < ordered 2
        $this->assertNull($partial->getWindowClosesAt());    // no window until the full line is delivered
    }

    public function testSecondParcelReachesFullQuantityAndStampsDelivery(): void
    {
        // The completing parcel: line 52 (qty 2) already had 1 unit delivered; the provider now reports
        // the cumulative 2 across both delivered parcels → delivered_qty 2 >= ordered 2 → stamp
        // delivered_at + window. The return window correctly starts at FULL-line delivery.
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);
        $completing = $this->item(null, null, 52, 2, 1); // 1 already delivered, not yet stamped
        $this->expectItemCollection([$completing]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));
        $this->itemResource->expects($this->once())->method('save')->with($completing)->willReturnSelf();

        $this->stamper()->stampItems(9, [52 => 2], '2026-06-10 14:30:00');

        $this->assertSame(2, $completing->getDeliveredQty());
        $this->assertSame('2026-06-10 14:30:00', $completing->getDeliveredAt());
        $this->assertSame('2026-06-17 14:30:00', $completing->getWindowClosesAt()); // +7 days
    }

    public function testRepeatedPartialPollIsIdempotent(): void
    {
        // A re-poll reporting the same partial qty must NOT re-save (no delivered_qty change, no stamp).
        $partial = $this->item(null, null, 52, 2, 1);
        $this->expectItemCollection([$partial]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        $this->itemResource->expects($this->never())->method('save');

        $this->stamper()->stampItems(9, [52 => 1], '2026-06-10 14:30:00');

        $this->assertSame(1, $partial->getDeliveredQty());
        $this->assertNull($partial->getDeliveredAt());
    }

    public function testDeliveredQtyNeverDecreasesOnRepoll(): void
    {
        // Defensive: a transient under-count from the provider (fewer units than already recorded) must
        // never reduce delivered_qty — a parcel can't un-deliver. max() holds the high-water mark.
        $item = $this->item(null, null, 52, 3, 2);
        $this->expectItemCollection([$item]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // max(2, 1) == 2 == existing → no change → no save.
        $this->itemResource->expects($this->never())->method('save');

        $this->stamper()->stampItems(9, [52 => 1], '2026-06-10 14:30:00');

        $this->assertSame(2, $item->getDeliveredQty());
        $this->assertNull($item->getDeliveredAt());
    }

    public function testStampsUndeliveredItemWriteOnceAndComputesWindow(): void
    {
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);

        $fresh = $this->item(null, null, 52, 1);                                       // to be stamped
        $already = $this->item('2026-06-01 00:00:00', '2026-06-08 00:00:00', 53, 1, 1); // must be left untouched
        $this->expectItemCollection([$fresh, $already]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // Only the fresh item is persisted (write-once); the already-stamped one is skipped.
        $this->itemResource->expects($this->once())->method('save')->with($fresh)->willReturnSelf();

        $this->stamper()->stampItems(9, [52 => 1, 53 => 1], '2026-06-10 14:30:00');

        $this->assertSame('2026-06-10 14:30:00', $fresh->getDeliveredAt());
        $this->assertSame('2026-06-17 14:30:00', $fresh->getWindowClosesAt()); // +7 days
        // Untouched item keeps its original stamps.
        $this->assertSame('2026-06-01 00:00:00', $already->getDeliveredAt());
        $this->assertSame('2026-06-08 00:00:00', $already->getWindowClosesAt());
    }

    public function testRecomputesSegmentDerivedAsMaxAcrossItems(): void
    {
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);

        $fresh = $this->item(null, null, 52, 1);
        $earlier = $this->item('2026-06-01 00:00:00', '2026-06-08 00:00:00', 53, 1, 1);
        $this->expectItemCollection([$fresh, $earlier]);

        /** @var SplitSegment $segment */
        $segment = $this->om->getObject(SplitSegment::class);
        $this->segmentLoader->method('loadById')->with(9)->willReturn($segment);
        $this->itemResource->method('save')->willReturnSelf();

        // Once every item is delivered, the segment's denormalised fields are the max.
        $this->segmentResource->expects($this->once())->method('save')->with($segment)->willReturnSelf();

        $this->stamper()->stampItems(9, [52 => 1, 53 => 1], '2026-06-10 14:30:00');

        $this->assertSame('2026-06-10 14:30:00', $segment->getData('delivered_at')); // max delivered
        $this->assertSame('2026-06-17 14:30:00', $segment->getData('window_closes_at')); // max window
    }

    public function testPartiallyDeliveredSegmentLeavesDenormUnset(): void
    {
        // A line still short of full qty means the segment is not "delivered" → the decorative
        // segment-level delivered_at/window stay null (recompute early-returns).
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(7);
        $partial = $this->item(null, null, 52, 2, 0);
        $this->expectItemCollection([$partial]);

        /** @var SplitSegment $segment */
        $segment = $this->om->getObject(SplitSegment::class);
        $this->segmentLoader->method('loadById')->willReturn($segment);
        $this->itemResource->method('save')->willReturnSelf();

        // No full delivery occurred → the segment denorm is never written.
        $this->segmentResource->expects($this->never())->method('save');

        $this->stamper()->stampItems(9, [52 => 1], '2026-06-10 14:30:00');

        $this->assertNull($segment->getData('delivered_at'));
    }

    public function testAlreadyFullyDeliveredSegmentIsANoOp(): void
    {
        $a = $this->item('2026-06-01 00:00:00', '2026-06-08 00:00:00', 52, 1, 1);
        $b = $this->item('2026-06-02 00:00:00', '2026-06-09 00:00:00', 53, 1, 1);
        $this->expectItemCollection([$a, $b]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));

        // Nothing to stamp → no item writes (a second poll after delivery is a no-op).
        $this->itemResource->expects($this->never())->method('save');

        $this->stamper()->stampItems(9, [52 => 1, 53 => 1], '2026-06-10 14:30:00');
    }

    public function testZeroDayConfigIsFlooredToOneDaySettlementBuffer(): void
    {
        // Even if returns are disabled (window=0), the vendor's escrow is held >= 1 day past
        // delivery — never released the same instant delivery is detected (settlement safety
        // margin; prevents a misconfigured 0 from green-lighting an instant release).
        $this->returnsConfig->method('getReturnWindowDays')->willReturn(0);

        $fresh = $this->item(null, null, 52, 1);
        $this->expectItemCollection([$fresh]);
        $this->segmentLoader->method('loadById')->willReturn($this->om->getObject(SplitSegment::class));
        $this->itemResource->method('save')->willReturnSelf();

        $this->stamper()->stampItems(9, [52 => 1], '2026-06-10 14:30:00');

        $this->assertSame('2026-06-11 14:30:00', $fresh->getWindowClosesAt()); // +1 day floor
    }
}
