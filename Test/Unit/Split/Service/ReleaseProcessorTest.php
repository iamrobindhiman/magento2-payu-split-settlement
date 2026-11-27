<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Service\ReleaseProcessor;
use RKD\PayuSplit\Split\Service\RefundLedger;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use RKD\PayuSplit\Split\Service\SegmentItemLoader;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The release executor is the keystone money mover: for an eligible held segment it CLAIMS
 * the release with an atomic compare-and-set (state ELIGIBLE→RELEASE_REQUESTED + attempts++ in
 * one conditional UPDATE, so two overlapping ticks can't both fire), then fires exactly one
 * release_settlement. It deliberately stops at RELEASE_REQUESTED — settlement is async (T+1)
 * and the sync response is ambiguous, so RELEASED is reached only by the later readback recon
 * (A5c-5). Tests pin the gates (delivery/window via SegmentEligibility, holds, a HELD/pending
 * refund leg = INV-19, parent-last = INV-14), the CAS claim (write-ahead BEFORE the gateway
 * call; a concurrent claim does not double-fire), idempotency, and the guard rails.
 */
class ReleaseProcessorTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: ReleaseProcessor, 1: array<string, object>}
     */
    private function make(array $overrides = []): array
    {
        $connection = $overrides['connection'] ?? $this->createMock(AdapterInterface::class);
        unset($overrides['connection']);
        $segmentResource = $this->createMock(SplitSegmentResource::class);
        $segmentResource->method('getConnection')->willReturn($connection);
        $segmentResource->method('getMainTable')->willReturn('rkd_payu_split_segment');

        $deps = [
            'gateway' => $this->createMock(PayuSplitClient::class),
            'repository' => $this->createMock(SplitOrderRepositoryInterface::class),
            'eligibility' => new SegmentEligibility(),
            'itemLoader' => $this->createMock(SegmentItemLoader::class),
            'holdLoader' => $this->createMock(SegmentHoldLoader::class),
            'refundLedger' => $this->createMock(RefundLedger::class),
            'segmentLoader' => $this->createMock(SegmentLoader::class),
            'segmentResource' => $segmentResource,
            'connection' => $connection,
            'dateTime' => $this->createMock(DateTime::class),
            'logger' => $this->createMock(LoggerInterface::class),
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }
        $deps['dateTime']->method('gmtDate')->willReturn('2026-06-20 00:00:00');

        $processor = new ReleaseProcessor(
            $deps['gateway'],
            $deps['repository'],
            $deps['eligibility'],
            $deps['itemLoader'],
            $deps['holdLoader'],
            $deps['refundLedger'],
            $deps['segmentLoader'],
            $deps['segmentResource'],
            $deps['dateTime'],
            $deps['logger']
        );
        return [$processor, $deps];
    }

    private function header(string $mihpayid = '100000000000000003'): SplitOrder
    {
        /** @var SplitOrder $h */
        $h = $this->om->getObject(SplitOrder::class);
        $h->setData('mihpayid', $mihpayid);
        return $h;
    }

    private function vendorSegment(string $state = SplitSegment::STATE_ELIGIBLE): SplitSegment
    {
        /** @var SplitSegment $s */
        $s = $this->om->getObject(SplitSegment::class);
        $s->setId(7);
        $s->setData('order_id', 42);
        $s->setData('seller_id', 18);
        $s->setData('segment_type', SplitSegment::TYPE_VENDOR);
        $s->setData('child_mid', '9000001');
        $s->setData('amount_paise', 51136);
        $s->setData('release_attempts', 0);
        $s->setState($state);
        return $s;
    }

    private function deliveredItem(): SplitSegmentItem
    {
        /** @var SplitSegmentItem $it */
        $it = $this->om->getObject(SplitSegmentItem::class);
        $it->setData('delivered_at', '2026-06-10 00:00:00');
        $it->setData('window_closes_at', '2026-06-17 00:00:00'); // past nowGmt 2026-06-20
        $it->setData('return_state', SplitSegmentItem::RETURN_NONE);
        return $it;
    }

    private function notReleased(): PayuApiResponse
    {
        return $this->createConfiguredMock(PayuApiResponse::class, ['isAlreadyReleased' => false]);
    }

    public function testEligibleVendorSegmentIsClaimedAndFiredOnce(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->with(42)->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->with(7)->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->with(7)->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->with(7)->willReturn([]);

        // Atomic CAS claim: conditional UPDATE flipping out of the releasable states, 1 row claimed.
        $deps['connection']->expects($this->once())->method('update')
            ->with(
                'rkd_payu_split_segment',
                $this->callback(fn($bind) => $bind['state'] === SplitSegment::STATE_RELEASE_REQUESTED),
                $this->callback(fn($where) => isset($where['segment_id = ?']) && (int) $where['segment_id = ?'] === 7)
            )
            ->willReturn(1);

        $deps['gateway']->expects($this->once())->method('releaseSettlement')
            ->with('100000000000000003', '9000001')->willReturn($this->notReleased());

        $processor->release($segment);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testConcurrentClaimDoesNotDoubleFire(): void
    {
        // CAS returns 0 rows → another tick already claimed this segment → must NOT fire.
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['connection']->method('update')->willReturn(0);

        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testUndeliveredItemBlocksRelease(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        /** @var SplitSegmentItem $undelivered */
        $undelivered = $this->om->getObject(SplitSegmentItem::class);
        $undelivered->setData('delivered_at', null);
        $deps['itemLoader']->method('loadItems')->willReturn([$undelivered]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testHeldRefundLegBlocksRelease(): void
    {
        // INV-19: a non-FAILED refund leg (incl. HELD) on the segment must block release.
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')
            ->willReturn([$this->createMock(\RKD\PayuSplit\Split\Model\SplitRefund::class)]);

        $deps['gateway']->expects($this->never())->method('releaseSettlement');
        $processor->release($segment);
    }

    public function testHoldBlocksRelease(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn(['RETURN_OPEN']);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $deps['gateway']->expects($this->never())->method('releaseSettlement');
        $processor->release($segment);
    }

    public function testParentReleasesOnlyAfterAllVendorsTerminal(): void
    {
        // INV-14: the commission segment releases LAST.
        /** @var SplitSegment $parent */
        $parent = $this->om->getObject(SplitSegment::class);
        $parent->setId(8);
        $parent->setData('order_id', 42);
        $parent->setData('seller_id', 0);
        $parent->setData('segment_type', SplitSegment::TYPE_COMMISSION);
        $parent->setData('child_mid', '5000000');
        $parent->setData('release_attempts', 0);
        $parent->setState(SplitSegment::STATE_ELIGIBLE);

        // one vendor still PENDING → parent must NOT release
        $pendingVendor = $this->vendorSegment(SplitSegment::STATE_PENDING);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['segmentLoader']->method('loadByOrderId')->with(42)->willReturn([$parent, $pendingVendor]);
        $deps['gateway']->expects($this->never())->method('releaseSettlement');
        $processor->release($parent);

        // now all vendors terminal → parent releases
        $releasedVendor = $this->vendorSegment(SplitSegment::STATE_RELEASED);
        [$processor2, $deps2] = $this->make();
        $deps2['repository']->method('getByOrderId')->willReturn($this->header());
        $deps2['holdLoader']->method('holdTypes')->willReturn([]);
        $deps2['refundLedger']->method('activeLegs')->willReturn([]);
        $deps2['segmentLoader']->method('loadByOrderId')->willReturn([$parent, $releasedVendor]);
        $deps2['connection']->method('update')->willReturn(1);
        $deps2['gateway']->expects($this->once())->method('releaseSettlement')
            ->with('100000000000000003', '5000000')->willReturn($this->notReleased());

        $parent->setState(SplitSegment::STATE_ELIGIBLE);
        $processor2->release($parent);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $parent->getState());
    }

    public function testClaimIsPersistedBeforeGatewayCall(): void
    {
        // Crash-safety: the CAS claim must persist BEFORE the release fires, so a crash during
        // the call leaves a recoverable record (recon readback resolves it).
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $order = [];
        $deps['connection']->method('update')->willReturnCallback(function () use (&$order) {
            $order[] = 'claim';
            return 1;
        });
        $deps['gateway']->method('releaseSettlement')->willReturnCallback(function () use (&$order) {
            $order[] = 'release';
            return $this->notReleased();
        });

        $processor->release($segment);
        $this->assertSame(['claim', 'release'], $order);
    }

    public function testTransportErrorLeavesSegmentReleaseRequested(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['connection']->method('update')->willReturn(1);
        $deps['gateway']->method('releaseSettlement')->willThrowException(new PayuSplitRequestException('timeout'));

        // No rethrow — the write-ahead claim is the durable record; recon re-fires.
        $processor->release($segment);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testNullChildMidSkipsWithoutClaimingOrFiring(): void
    {
        $segment = $this->vendorSegment();
        $segment->setData('child_mid', null);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testNoMihpayidSkipsWithoutClaimingOrFiring(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header(''));

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testAlreadyRequestedSegmentIsNotReFired(): void
    {
        $segment = $this->vendorSegment(SplitSegment::STATE_RELEASE_REQUESTED);
        [$processor, $deps] = $this->make();

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }
}
