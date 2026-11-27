<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\ReleaseRequestedLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The recon work-list loader: selects RELEASE_REQUESTED segments, oldest-requested first,
 * bounded by the scan batch size. Pins the filter so the recon never picks up a segment in any
 * other state.
 */
class ReleaseRequestedLoaderTest extends TestCase
{
    public function testLoadsRequestedSegmentsOldestFirstBounded(): void
    {
        $om = new ObjectManager($this);
        $segments = [$om->getObject(SplitSegment::class), $om->getObject(SplitSegment::class)];

        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addFieldToFilter')
            ->with('state', SplitSegment::STATE_RELEASE_REQUESTED)->willReturnSelf();
        $collection->expects($this->once())->method('setOrder')
            ->with('release_requested_at', 'ASC')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(200)->willReturnSelf();
        $collection->method('getItems')->willReturn($segments);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $config = $this->createMock(SplitConfig::class);
        $config->method('getScanBatchSize')->willReturn(200);

        $loaded = (new ReleaseRequestedLoader($collectionFactory, $config))->loadRequested();

        $this->assertCount(2, $loaded);
    }
}
