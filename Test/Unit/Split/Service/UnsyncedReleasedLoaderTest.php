<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\UnsyncedReleasedLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The Webkul-mirror reconcile work-list loader: selects RELEASED segments whose payout was not yet
 * mirrored (webkul_synced_at IS NULL), oldest-released first, bounded. Pins the filter so a
 * still-unreleased or already-mirrored segment is never re-mirrored.
 */
class UnsyncedReleasedLoaderTest extends TestCase
{
    public function testLoadsReleasedUnsyncedSegmentsBounded(): void
    {
        $om = new ObjectManager($this);
        $segments = [$om->getObject(SplitSegment::class)];

        $collection = $this->createMock(Collection::class);
        $filters = [];
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use (&$filters, $collection) {
            $filters[$field] = $cond;
            return $collection;
        });
        $collection->expects($this->once())->method('setOrder')->with('released_at', 'ASC')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(200)->willReturnSelf();
        $collection->method('getItems')->willReturn($segments);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $config = $this->createMock(SplitConfig::class);
        $config->method('getScanBatchSize')->willReturn(200);

        $loaded = (new UnsyncedReleasedLoader($collectionFactory, $config))->loadUnsynced();

        $this->assertCount(1, $loaded);
        $this->assertSame(SplitSegment::STATE_RELEASED, $filters['state']);
        $this->assertSame(['null' => true], $filters['webkul_synced_at']);
    }
}
