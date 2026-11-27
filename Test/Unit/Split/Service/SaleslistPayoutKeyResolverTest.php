<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Service\SaleslistPayoutKeyResolver;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\Collection as SaleslistCollection;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory as SaleslistCollectionFactory;
use Webkul\Marketplace\Model\Saleslist;

/**
 * A5c-4b — resolves the Webkul saleslist row ids the admin "Pay Seller" actions submit
 * (autoorderid / wksellerorderids CSV) into the (order_id, seller_id) keys the guard checks.
 */
class SaleslistPayoutKeyResolverTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    private function row(int $orderId, int $sellerId): Saleslist
    {
        /** @var Saleslist $row */
        $row = $this->om->getObject(Saleslist::class);
        $row->setData('order_id', $orderId);
        $row->setData('seller_id', $sellerId);
        return $row;
    }

    /**
     * @param Saleslist[] $rows
     */
    private function resolver(array $rows, ?array $expectedFilterIds = null): SaleslistPayoutKeyResolver
    {
        $collection = $this->createMock(SaleslistCollection::class);
        if ($expectedFilterIds !== null) {
            $collection->expects($this->once())
                ->method('addFieldToFilter')
                ->with('entity_id', ['in' => $expectedFilterIds])
                ->willReturnSelf();
        } else {
            $collection->method('addFieldToFilter')->willReturnSelf();
        }
        $collection->method('getItems')->willReturn($rows);

        $factory = $this->createMock(SaleslistCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new SaleslistPayoutKeyResolver($factory);
    }

    public function testResolvesEntityIdsToOrderSellerKeys(): void
    {
        $resolver = $this->resolver(
            [$this->row(42, 18), $this->row(41, 7)],
            [55, 56]
        );

        $keys = $resolver->resolve([55, 56]);

        $this->assertSame(
            [
                ['order_id' => 42, 'seller_id' => 18],
                ['order_id' => 41, 'seller_id' => 7],
            ],
            $keys
        );
    }

    public function testReturnsEmptyAndNeverQueriesForEmptyInput(): void
    {
        $factory = $this->createMock(SaleslistCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $resolver = new SaleslistPayoutKeyResolver($factory);

        $this->assertSame([], $resolver->resolve([]));
    }

    public function testFiltersOutNonPositiveAndNonNumericIdsBeforeQuerying(): void
    {
        // CSV from the request can carry blanks/zeroes ('5,,0,-1'); only the real id is queried.
        $resolver = $this->resolver([$this->row(42, 18)], [5]);

        $keys = $resolver->resolve([5, 0, -1]);

        $this->assertSame([['order_id' => 42, 'seller_id' => 18]], $keys);
    }

    public function testReturnsEmptyWhenNoRowsResolve(): void
    {
        $resolver = $this->resolver([], [999]);

        $this->assertSame([], $resolver->resolve([999]));
    }
}
