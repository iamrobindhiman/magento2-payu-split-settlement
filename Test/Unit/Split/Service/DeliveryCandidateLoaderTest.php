<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\UndeliveredSegments;
use RKD\PayuSplit\Split\Service\DeliveryCandidateLoader;
use PHPUnit\Framework\TestCase;

/**
 * The loader maps undelivered-segment rows (string columns off the DB) into typed
 * DeliveryCandidate VOs, integer-cast. An empty work-list yields no candidates.
 */
class DeliveryCandidateLoaderTest extends TestCase
{
    public function testMapsRowsToIntegerCastCandidates(): void
    {
        $resource = $this->createMock(UndeliveredSegments::class);
        $resource->method('getUndeliveredRows')->willReturn([
            ['segment_id' => '9', 'order_id' => '41', 'seller_id' => '18'],
            ['segment_id' => '10', 'order_id' => '41', 'seller_id' => '20'],
        ]);

        $candidates = (new DeliveryCandidateLoader($resource))->loadCandidates();

        $this->assertCount(2, $candidates);
        $this->assertSame(9, $candidates[0]->segmentId);
        $this->assertSame(41, $candidates[0]->orderId);
        $this->assertSame(18, $candidates[0]->sellerId);
        $this->assertSame(20, $candidates[1]->sellerId);
    }

    public function testEmptyWorkListYieldsNoCandidates(): void
    {
        $resource = $this->createMock(UndeliveredSegments::class);
        $resource->method('getUndeliveredRows')->willReturn([]);

        $this->assertSame([], (new DeliveryCandidateLoader($resource))->loadCandidates());
    }
}
