<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\EligibleSegments;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentFactory;
use RKD\PayuSplit\Split\Service\ReleaseCandidateLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Hydrates each releasable-segment row from the coarse pre-filter into a SplitSegment model
 * for the release processor. A row whose segment fails to load (deleted underneath the scan)
 * is skipped, never released.
 */
class ReleaseCandidateLoaderTest extends TestCase
{
    public function testHydratesVendorAndParentRowsIntoSegments(): void
    {
        $om = new ObjectManager($this);
        $eligible = $this->createMock(EligibleSegments::class);
        $eligible->method('getReleasableVendorRows')->willReturn([
            ['segment_id' => '7', 'order_id' => '42', 'seller_id' => '18'],
            ['segment_id' => '9', 'order_id' => '41', 'seller_id' => '18'],
        ]);
        // Parent commission candidate appended after the vendor rows.
        $eligible->method('getReleasableParentRows')->willReturn([
            ['segment_id' => '3', 'order_id' => '42', 'seller_id' => '0'],
        ]);

        $factory = $this->createMock(SplitSegmentFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $om->getObject(SplitSegment::class));

        $resource = $this->createMock(SplitSegmentResource::class);
        $resource->method('load')->willReturnCallback(function (SplitSegment $seg, $id) {
            $seg->setId($id); // emulate a successful load (non-zero id)
        });

        $candidates = (new ReleaseCandidateLoader($eligible, $factory, $resource))->loadCandidates();

        $this->assertCount(3, $candidates);
        $this->assertSame([7, 9, 3], array_map(fn($s) => (int) $s->getId(), $candidates));
    }

    public function testSkipsSegmentsThatFailToLoad(): void
    {
        $om = new ObjectManager($this);
        $eligible = $this->createMock(EligibleSegments::class);
        $eligible->method('getReleasableVendorRows')->willReturn([
            ['segment_id' => '7', 'order_id' => '42', 'seller_id' => '18'],
        ]);
        $eligible->method('getReleasableParentRows')->willReturn([]);

        $factory = $this->createMock(SplitSegmentFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $om->getObject(SplitSegment::class));

        // load() leaves the model empty (id stays 0) — the row no longer resolves.
        $resource = $this->createMock(SplitSegmentResource::class);

        $candidates = (new ReleaseCandidateLoader($eligible, $factory, $resource))->loadCandidates();

        $this->assertSame([], $candidates);
    }
}
