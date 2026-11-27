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
     * @param int $existingSize
     * @return array{0: SegmentHoldWriter, 1: SplitHoldResource}
     */
    private function make(int $existingSize): array
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn($existingSize);
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
}
