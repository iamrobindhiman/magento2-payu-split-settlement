<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SettlementReadbackResult;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\ReleaseReconciler;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SettlementReadbackReader;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The release readback reconciliation logic (A5c-5). Tests pin the only path that promotes
 * RELEASE_REQUESTED → RELEASED, and the safety rails around it:
 *  - a matching readback (settled == amount − refunded) → CAS to RELEASED + the released event;
 *  - a mismatch → FROZEN hold + CRITICAL, NEVER RELEASED, NO event (INV-18 hard stop);
 *  - no settlement signal → stays RELEASE_REQUESTED (the only UAT path), aged-strand alarm;
 *  - frozen / non-requested / no-mihpayid / no-child-key → skip before any gateway call;
 *  - a lost CAS (concurrent recon) → no event dispatched (idempotency).
 */
class ReleaseReconcilerTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: ReleaseReconciler, 1: array<string, object>}
     */
    private function make(array $overrides = []): array
    {
        $connection = $overrides['connection'] ?? $this->createMock(AdapterInterface::class);
        unset($overrides['connection']);
        $segmentResource = $this->createMock(SplitSegmentResource::class);
        $segmentResource->method('getConnection')->willReturn($connection);
        $segmentResource->method('getMainTable')->willReturn('rkd_payu_split_segment');

        $config = $this->createMock(SplitConfig::class);
        $config->method('getAlertDays')->willReturn(23);
        $config->method('getScanBatchSize')->willReturn(200);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-06-20 00:00:00');

        $deps = [
            'gateway' => $this->createMock(PayuSplitClient::class),
            'repository' => $this->createMock(SplitOrderRepositoryInterface::class),
            'readbackReader' => $this->createMock(SettlementReadbackReader::class),
            'holdLoader' => $this->createMock(SegmentHoldLoader::class),
            'holdWriter' => $this->createMock(SegmentHoldWriter::class),
            'segmentResource' => $segmentResource,
            'connection' => $connection,
            'config' => $config,
            'dateTime' => $dateTime,
            'eventManager' => $this->createMock(EventManager::class),
            'logger' => $this->createMock(LoggerInterface::class),
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }

        $reconciler = new ReleaseReconciler(
            $deps['gateway'],
            $deps['repository'],
            $deps['readbackReader'],
            $deps['holdLoader'],
            $deps['holdWriter'],
            $deps['segmentResource'],
            $deps['config'],
            $deps['dateTime'],
            $deps['eventManager'],
            $deps['logger']
        );
        return [$reconciler, $deps];
    }

    /**
     * @param array<string, mixed> $data
     * @return SplitSegment
     */
    private function segment(array $data = []): SplitSegment
    {
        $merged = array_merge([
            'segment_id' => 7,
            'order_id' => 42,
            'seller_id' => 18,
            'segment_type' => SplitSegment::TYPE_VENDOR,
            'amount_paise' => 71928,
            'refunded_paise' => 0,
            'child_merchant_key' => 'CHLD01',
            'state' => SplitSegment::STATE_RELEASE_REQUESTED,
            'release_requested_at' => '2026-06-18 00:00:00',
        ], $data);
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setData($merged);
        $segment->setId((int) $merged['segment_id']); // getId() reads the model id field, not segment_id
        return $segment;
    }

    /**
     * @param string $mihpayid
     * @return SplitOrder
     */
    private function header(string $mihpayid = '100000000000000001'): SplitOrder
    {
        $header = $this->om->getObject(SplitOrder::class);
        $header->setData(['order_id' => 42, 'mihpayid' => $mihpayid, 'split_done_at' => '2026-06-17 00:00:00']);
        return $header;
    }

    private function emptyResponse(): PayuApiResponse
    {
        return PayuApiResponse::fromFields([], 'json', []);
    }

    public function testMatchingReadbackReleasesAndDispatchesEvent(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment(['amount_paise' => 71928, 'refunded_paise' => 1000]);
        $deps['repository']->method('getByOrderId')->with(42)->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        // expected net = 71928 - 1000 = 70928
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::found(70928));

        $deps['connection']->expects($this->once())->method('update')
            ->with(
                'rkd_payu_split_segment',
                $this->callback(fn($bind) => $bind['state'] === SplitSegment::STATE_RELEASED
                    && $bind['released_paise'] === 70928),
                $this->callback(fn($where) => (int) $where['segment_id = ?'] === 7
                    && $where['state = ?'] === SplitSegment::STATE_RELEASE_REQUESTED)
            )
            ->willReturn(1);
        $deps['eventManager']->expects($this->once())->method('dispatch')
            ->with(ReleaseReconciler::EVENT_SEGMENT_RELEASED, $this->callback(fn($d) => $d['segment'] === $segment));
        $deps['holdWriter']->expects($this->never())->method('place');

        $reconciler->reconcile($segment);

        $this->assertSame(SplitSegment::STATE_RELEASED, $segment->getState());
        $this->assertSame(70928, (int) $segment->getData('released_paise'));
    }

    public function testMismatchFreezesAndNeverReleases(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment(['amount_paise' => 71928, 'refunded_paise' => 0]); // expect 71928
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        // PayU settled a DIFFERENT amount than our books expect.
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::found(70000));

        $deps['holdWriter']->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_FROZEN, $this->stringContains('readback mismatch'));
        $deps['connection']->expects($this->never())->method('update');
        $deps['eventManager']->expects($this->never())->method('dispatch');
        $deps['logger']->expects($this->atLeastOnce())->method('critical');

        $reconciler->reconcile($segment);

        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testNoSettlementSignalLeavesRequestedAndDoesNotRelease(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment(); // release_requested_at recent → not stranded
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::notFound());

        $deps['connection']->expects($this->never())->method('update');
        $deps['eventManager']->expects($this->never())->method('dispatch');
        $deps['holdWriter']->expects($this->never())->method('place');
        $deps['logger']->expects($this->never())->method('critical');

        $reconciler->reconcile($segment);

        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testStrandedSegmentRaisesComplianceAlarm(): void
    {
        [$reconciler, $deps] = $this->make();
        // Requested long ago (> alert threshold 23d) with still no settlement → strand alarm.
        $segment = $this->segment(['release_requested_at' => '2026-05-01 00:00:00']);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::notFound());

        $deps['logger']->expects($this->once())->method('critical')
            ->with($this->stringContains('stranded in RELEASE_REQUESTED'));
        $deps['connection']->expects($this->never())->method('update');

        $reconciler->reconcile($segment);
    }

    public function testConcurrentPromotionDoesNotDispatchEvent(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::found(71928));
        // CAS affects 0 rows: a concurrent recon already promoted it.
        $deps['connection']->method('update')->willReturn(0);

        $deps['eventManager']->expects($this->never())->method('dispatch');

        $reconciler->reconcile($segment);
    }

    public function testFrozenSegmentIsSkippedBeforeGatewayCall(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment();
        $deps['holdLoader']->method('holdTypes')->with(7)->willReturn([SplitHold::TYPE_FROZEN]);

        $deps['gateway']->expects($this->never())->method('getAggregatorTransactions');
        $deps['connection']->expects($this->never())->method('update');
        $deps['eventManager']->expects($this->never())->method('dispatch');

        $reconciler->reconcile($segment);
    }

    public function testNonRequestedSegmentIsIgnored(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment(['state' => SplitSegment::STATE_RELEASED]);

        $deps['holdLoader']->expects($this->never())->method('holdTypes');
        $deps['gateway']->expects($this->never())->method('getAggregatorTransactions');

        $reconciler->reconcile($segment);
    }

    public function testMissingMihpayidIsCriticalAndNoGatewayCall(): void
    {
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment();
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header(''));

        $deps['logger']->expects($this->once())->method('critical')->with($this->stringContains('no mihpayid'));
        $deps['gateway']->expects($this->never())->method('getAggregatorTransactions');

        $reconciler->reconcile($segment);
    }

    public function testMissingChildKeyFreezesParentAndNoGatewayCall(): void
    {
        // The parent commission segment is persisted with child_merchant_key = null; without it
        // the readback cannot match, so it freezes (durable + one alarm) rather than spamming.
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment([
            'segment_id' => 3,
            'seller_id' => 0,
            'segment_type' => SplitSegment::TYPE_COMMISSION,
            'child_merchant_key' => null,
        ]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());

        $deps['holdWriter']->expects($this->once())->method('place')
            ->with(3, SplitHold::TYPE_FROZEN, $this->stringContains('child_merchant_key'));
        $deps['logger']->expects($this->once())->method('critical')
            ->with($this->stringContains('no child merchant key'));
        $deps['gateway']->expects($this->never())->method('getAggregatorTransactions');

        $reconciler->reconcile($segment);
    }

    public function testMissingReleaseRequestedAnchorAlarmsAsStranded(): void
    {
        // A RELEASE_REQUESTED segment with no anchor is a corruption/legacy row — it must alarm
        // LOUDER than an old one, never sit silent (RBI never-strand-unalarmed).
        [$reconciler, $deps] = $this->make();
        $segment = $this->segment(['release_requested_at' => null]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::notFound());

        $deps['logger']->expects($this->once())->method('critical')
            ->with($this->stringContains('stranded in RELEASE_REQUESTED'));

        $reconciler->reconcile($segment);
    }

    public function testParentCommissionSegmentReleasesUniformly(): void
    {
        [$reconciler, $deps] = $this->make();
        $parent = $this->segment([
            'segment_id' => 3,
            'seller_id' => 0,
            'segment_type' => SplitSegment::TYPE_COMMISSION,
            'amount_paise' => 7992,
            'refunded_paise' => 0,
            'child_merchant_key' => 'PRNT01',
        ]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['gateway']->method('getAggregatorTransactions')->willReturn($this->emptyResponse());
        $deps['readbackReader']->method('read')->willReturn(SettlementReadbackResult::found(7992));
        $deps['connection']->method('update')->willReturn(1);

        $deps['eventManager']->expects($this->once())->method('dispatch')
            ->with(ReleaseReconciler::EVENT_SEGMENT_RELEASED, $this->anything());

        $reconciler->reconcile($parent);

        $this->assertSame(SplitSegment::STATE_RELEASED, $parent->getState());
    }
}
