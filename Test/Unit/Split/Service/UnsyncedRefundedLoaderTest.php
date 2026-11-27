<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\UnsyncedRefundedLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The Webkul refund-close reconcile work-list loader (gate blocker #4): selects VENDOR segments that
 * are REFUNDED/PARTIAL_REFUNDED but not yet closed in Webkul (webkul_synced_at IS NULL), oldest
 * first, bounded. Pins the filter so the commission parent (no Webkul ledger) is never selected and
 * an already-closed segment is never re-closed.
 */
class UnsyncedRefundedLoaderTest extends TestCase
{
    public function testLoadsRefundedUnsyncedVendorSegmentsBounded(): void
    {
        $om = new ObjectManager($this);
        $segments = [$om->getObject(SplitSegment::class)];

        $collection = $this->createMock(Collection::class);
        $filters = [];
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use (&$filters, $collection) {
            $filters[$field] = $cond;
            return $collection;
        });
        $collection->expects($this->once())->method('setOrder')->with('updated_at', 'ASC')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(200)->willReturnSelf();
        $collection->method('getItems')->willReturn($segments);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $config = $this->createMock(SplitConfig::class);
        $config->method('getScanBatchSize')->willReturn(200);

        $loaded = (new UnsyncedRefundedLoader($collectionFactory, $config))->loadUnsynced();

        $this->assertCount(1, $loaded);
        $this->assertSame(
            ['in' => [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_PARTIAL_REFUNDED]],
            $filters['state']
        );
        $this->assertSame(SplitSegment::TYPE_VENDOR, $filters['segment_type']);
        $this->assertSame(['null' => true], $filters['webkul_synced_at']);
    }
}
