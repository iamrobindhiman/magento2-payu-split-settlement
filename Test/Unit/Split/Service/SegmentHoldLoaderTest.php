<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold\Collection as HoldCollection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitHold\CollectionFactory as HoldCollectionFactory;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Loads the active hold-type strings on a segment so SegmentEligibility can block release
 * while any hold (return-open, replacement, KYC-suspended, manual, frozen) is present.
 */
class SegmentHoldLoaderTest extends TestCase
{
    public function testReturnsHoldTypesForSegment(): void
    {
        $om = new ObjectManager($this);
        $h1 = $om->getObject(SplitHold::class);
        $h1->setData('hold_type', SplitHold::TYPE_RETURN_OPEN);
        $h2 = $om->getObject(SplitHold::class);
        $h2->setData('hold_type', SplitHold::TYPE_MANUAL);

        $collection = $this->createMock(HoldCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn([$h1, $h2]);
        $factory = $this->createMock(HoldCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame(
            [SplitHold::TYPE_RETURN_OPEN, SplitHold::TYPE_MANUAL],
            (new SegmentHoldLoader($factory))->holdTypes(7)
        );
    }

    public function testNoHoldsYieldsEmptyArray(): void
    {
        $collection = $this->createMock(HoldCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);
        $factory = $this->createMock(HoldCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $this->assertSame([], (new SegmentHoldLoader($factory))->holdTypes(7));
    }
}
