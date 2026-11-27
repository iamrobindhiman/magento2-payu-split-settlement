<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Service\RefundLegLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The refund-recon work-list loader (gate item #6): selects the pollable refund legs — QUEUED or
 * PENDING with a non-null payu_request_id — oldest-first, bounded by the scan batch size. The
 * non-null request-id filter is load-bearing: a FULL_UNWIND obligation / absent-parent leg is
 * written PENDING with a NULL request id and is NOT pollable, so it must never enter the work-list.
 */
class RefundLegLoaderTest extends TestCase
{
    public function testLoadsPollableLegsOldestFirstBounded(): void
    {
        $om = new ObjectManager($this);
        $legs = [$om->getObject(SplitRefund::class), $om->getObject(SplitRefund::class)];

        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $cond) use (&$filters, $collection) {
                $filters[$field] = $cond;
                return $collection;
            }
        );
        $collection->expects($this->once())->method('setOrder')->with('created_at', 'ASC')->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(200)->willReturnSelf();
        $collection->method('getItems')->willReturn($legs);

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $config = $this->createMock(SplitConfig::class);
        $config->method('getScanBatchSize')->willReturn(200);

        $loaded = (new RefundLegLoader($collectionFactory, $config))->loadPollable();

        $this->assertCount(2, $loaded);
        $this->assertSame(
            ['in' => [SplitRefund::STATE_QUEUED, SplitRefund::STATE_PENDING]],
            $filters['state'],
            'must poll only QUEUED + PENDING legs'
        );
        $this->assertSame(
            ['notnull' => true],
            $filters['payu_request_id'],
            'must exclude un-pollable (null request id) FULL_UNWIND/absent-parent legs'
        );
    }
}
