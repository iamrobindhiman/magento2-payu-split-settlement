<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold as SplitHoldResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitHoldFactory;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The hold writer places a hold row, idempotently on (segment_id, hold_type): a hold that
 * already exists is not written twice (a re-running recon must not pile up duplicate FROZEN rows).
 */
class SegmentHoldWriterTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param int|int[] $existingSize getSize() result, or a list returned on consecutive calls
     * @return array{0: SegmentHoldWriter, 1: SplitHoldResource}
     */
    private function make(int|array $existingSize): array
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        if (is_array($existingSize)) {
            $collection->method('getSize')->willReturnOnConsecutiveCalls(...$existingSize);
        } else {
            $collection->method('getSize')->willReturn($existingSize);
        }
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $holdFactory = $this->createMock(SplitHoldFactory::class);
        $holdFactory->method('create')->willReturnCallback(fn() => $this->om->getObject(SplitHold::class));

        $resource = $this->createMock(SplitHoldResource::class);

        return [new SegmentHoldWriter($collectionFactory, $holdFactory, $resource), $resource];
    }

    public function testPlacesHoldWhenNoneExists(): void
    {
        [$writer, $resource] = $this->make(0);

        $resource->expects($this->once())->method('save')
            ->with($this->callback(function (SplitHold $hold) {
                return $hold->getSegmentId() === 7
                    && $hold->getHoldType() === SplitHold::TYPE_FROZEN
                    && $hold->getData('note') === 'mismatch';
            }));

        $writer->place(7, SplitHold::TYPE_FROZEN, 'mismatch');
    }

    public function testDoesNotPlaceDuplicateHold(): void
    {
        [$writer, $resource] = $this->make(1);

        $resource->expects($this->never())->method('save');

        $writer->place(7, SplitHold::TYPE_FROZEN, 'mismatch');
    }

    public function testConcurrentDuplicateInsertIsSwallowedAsNoOp(): void
    {
        // Lost the exists()→save() race: exists() was false, save() hit the unique index, and a
        // re-check now shows the hold present. The unique index is the authoritative idempotency
        // guard, so this must be a silent no-op (no exception leaks to the Throwable-guarded caller).
        [$writer, $resource] = $this->make([0, 1]); // exists() false pre-save, true on re-check
        $resource->method('save')->willThrowException(new \RuntimeException('duplicate key'));

        $writer->place(7, SplitHold::TYPE_CANCELED, 'order cancelled');

        $this->addToAssertionCount(1); // reached here without an exception escaping
    }

    public function testGenuineSaveFailureIsRethrown(): void
    {
        // save() failed AND the row is still absent — a real failure, not the dedup index. Re-throw.
        [$writer, $resource] = $this->make([0, 0]); // exists() false pre-save, still false on re-check
        $resource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        $writer->place(7, SplitHold::TYPE_CANCELED, 'order cancelled');
    }

    public function testRemoveDeletesMatchingHoldAndReturnsCount(): void
    {
        // The break-glass hold-clear primitive (#11): remove the (segment_id, hold_type) row.
        $hold = $this->om->getObject(SplitHold::class);
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn([$hold]);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $resource = $this->createMock(SplitHoldResource::class);
        $resource->expects($this->once())->method('delete')->with($hold);

        $writer = new SegmentHoldWriter($collectionFactory, $this->createMock(SplitHoldFactory::class), $resource);

        $this->assertSame(1, $writer->remove(7, SplitHold::TYPE_FROZEN));
    }

    public function testRemoveReturnsZeroWhenNoMatchingHold(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $resource = $this->createMock(SplitHoldResource::class);
        $resource->expects($this->never())->method('delete');

        $writer = new SegmentHoldWriter($collectionFactory, $this->createMock(SplitHoldFactory::class), $resource);

        $this->assertSame(0, $writer->remove(7, SplitHold::TYPE_FROZEN));
    }
}
