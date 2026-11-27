<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\RefundStatusResult;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Service\RefundReconciler;
use RKD\PayuSplit\Split\Service\RefundStatusReader;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refund-status reconciler (gate item #6) — the only path that advances a refund leg past
 * QUEUED/PENDING. It polls check_action_status_txnid per leg and:
 *  - SUCCESS  → CAS-promote the leg QUEUED/PENDING→SUCCESS (no money moves — confirmation only);
 *  - FAILURE  → CAS-promote→FAILED, FREEZE the segment + CRITICAL alarm (M-5: the customer was NOT
 *              refunded and the optimistic refunded_paise bump is now wrong — surfaced, never
 *              silently acted on);
 *  - PROCESSING/NONE → leave the leg; strand-alarm (by leg age) past the alert threshold.
 * Tests pin the CAS exactly-once promotion, the freeze-on-failure, idempotency, and that an
 * unrecognized/absent status never promotes.
 */
class RefundReconcilerTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: RefundReconciler, 1: array<string, object>}
     */
    private function make(array $overrides = []): array
    {
        $connection = $overrides['connection'] ?? $this->createMock(AdapterInterface::class);
        unset($overrides['connection']);
        $refundResource = $this->createMock(SplitRefundResource::class);
        $refundResource->method('getConnection')->willReturn($connection);
        $refundResource->method('getMainTable')->willReturn('rkd_payu_refund');

        $config = $this->createMock(SplitConfig::class);
        $config->method('getAlertDays')->willReturn(23);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-06-20 00:00:00');

        $deps = [
            'gateway' => $this->createMock(PayuSplitClient::class),
            'statusReader' => $this->createMock(RefundStatusReader::class),
            'holdWriter' => $this->createMock(SegmentHoldWriter::class),
            'refundResource' => $refundResource,
            'connection' => $connection,
            'config' => $config,
            'dateTime' => $dateTime,
            'logger' => $this->createMock(LoggerInterface::class),
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }
        // Default: the gateway returns a benign empty response; the status reader drives the branch.
        $deps['gateway']->method('checkActionStatus')->willReturn(PayuApiResponse::fromFields([], 'json', []));

        $reconciler = new RefundReconciler(
            $deps['gateway'],
            $deps['statusReader'],
            $deps['holdWriter'],
            $deps['refundResource'],
            $deps['config'],
            $deps['dateTime'],
            $deps['logger']
        );
        return [$reconciler, $deps];
    }

    /**
     * @param array<string, mixed> $data
     * @return SplitRefund
     */
    private function leg(array $data = []): SplitRefund
    {
        $merged = array_merge([
            'refund_id' => 5,
            'order_id' => 42,
            'segment_id' => 7,
            'payu_request_id' => '139943118',
            'state' => SplitRefund::STATE_QUEUED,
            'reason' => SplitRefund::REASON_VENDOR_CANCEL,
            'created_at' => '2026-06-19 00:00:00', // 1 day old → within alert threshold
        ], $data);
        /** @var SplitRefund $leg */
        $leg = $this->om->getObject(SplitRefund::class);
        $leg->setData($merged);
        $leg->setId((int) $merged['refund_id']);
        return $leg;
    }

    public function testSuccessPromotesLegToSuccess(): void
    {
        $leg = $this->leg();
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::success());

        // CAS: conditional UPDATE flipping QUEUED/PENDING → SUCCESS for this refund_id.
        $deps['connection']->expects($this->once())->method('update')
            ->with(
                'rkd_payu_refund',
                $this->callback(fn($bind) => $bind['state'] === SplitRefund::STATE_SUCCESS),
                $this->callback(fn($where) => (int) $where['refund_id = ?'] === 5)
            )
            ->willReturn(1);
        $deps['holdWriter']->expects($this->never())->method('place');
        $deps['logger']->expects($this->never())->method('critical');

        $reconciler->reconcile($leg);
        $this->assertSame(SplitRefund::STATE_SUCCESS, $leg->getState());
    }

    public function testFailurePromotesToFailedFreezesSegmentAndAlarms(): void
    {
        $leg = $this->leg();
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::failure());
        $deps['connection']->method('update')->willReturn(1); // CAS won

        // The fail-flip + freeze commit together (crash-safe): one transaction, one commit.
        $deps['connection']->expects($this->once())->method('commit');
        $deps['holdWriter']->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_FROZEN, $this->stringContains('refund'));
        $deps['logger']->expects($this->atLeastOnce())->method('critical');

        $reconciler->reconcile($leg);
        $this->assertSame(SplitRefund::STATE_FAILED, $leg->getState());
    }

    public function testFailureFreezeThrowingRollsBackAndLeavesLegInFlight(): void
    {
        // Crash-safety: if placing the FROZEN hold throws mid-transaction, the whole FAILURE
        // handling rolls back — the leg stays QUEUED (retried next tick), never a FAILED-but-
        // unfrozen strand, and no CRITICAL alarm fires for a non-committed failure.
        $leg = $this->leg();
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->method('place')->willThrowException(new \RuntimeException('hold insert failed'));
        [$reconciler, $deps] = $this->make(['holdWriter' => $holdWriter]);
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::failure());
        $deps['connection']->method('update')->willReturn(1); // CAS won

        $deps['connection']->expects($this->once())->method('rollBack');
        $deps['logger']->expects($this->never())->method('critical');
        $deps['logger']->expects($this->atLeastOnce())->method('error');

        $reconciler->reconcile($leg);
        // Not promoted in-memory — left QUEUED for the clean retry.
        $this->assertSame(SplitRefund::STATE_QUEUED, $leg->getState());
    }

    public function testFailureCasLostDoesNotFreezeOrAlarm(): void
    {
        // A concurrent tick already promoted this leg → the conditional UPDATE affects 0 rows →
        // we must NOT freeze the segment or alarm a second time.
        $leg = $this->leg();
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::failure());
        $deps['connection']->method('update')->willReturn(0); // CAS lost

        $deps['holdWriter']->expects($this->never())->method('place');
        $deps['logger']->expects($this->never())->method('critical');

        $reconciler->reconcile($leg);
    }

    public function testProcessingLeavesLegUntouched(): void
    {
        $leg = $this->leg();
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::processing());

        $deps['connection']->expects($this->never())->method('update');
        $deps['holdWriter']->expects($this->never())->method('place');
        $deps['logger']->expects($this->never())->method('critical');

        $reconciler->reconcile($leg);
        $this->assertSame(SplitRefund::STATE_QUEUED, $leg->getState());
    }

    public function testNoSignalWithinThresholdWaits(): void
    {
        $leg = $this->leg(); // 1 day old
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::noSignal());

        $deps['connection']->expects($this->never())->method('update');
        $deps['logger']->expects($this->never())->method('critical');

        $reconciler->reconcile($leg);
        $this->assertSame(SplitRefund::STATE_QUEUED, $leg->getState());
    }

    public function testNoSignalPastThresholdStrandAlarms(): void
    {
        // A leg stuck QUEUED past the alert threshold with no PayU signal is a strand (the customer
        // may be unrefunded) — it must alarm, never sit silent (RBI never-strand-unalarmed).
        $leg = $this->leg(['created_at' => '2026-05-01 00:00:00']); // ~50 days old > 23
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::noSignal());

        $deps['logger']->expects($this->once())->method('critical')
            ->with($this->stringContains('stranded'));
        $deps['connection']->expects($this->never())->method('update');

        $reconciler->reconcile($leg);
    }

    public function testNonQueuedLegIsIgnoredBeforeGatewayCall(): void
    {
        $leg = $this->leg(['state' => SplitRefund::STATE_SUCCESS]);
        [$reconciler, $deps] = $this->make();

        $deps['gateway']->expects($this->never())->method('checkActionStatus');
        $deps['connection']->expects($this->never())->method('update');

        $reconciler->reconcile($leg);
    }

    public function testNullRequestIdIsSkippedBeforeGatewayCall(): void
    {
        // A FULL_UNWIND obligation / absent-parent leg has no request id — not pollable; skip it
        // (the loader excludes these, but defend in depth).
        $leg = $this->leg(['payu_request_id' => null]);
        [$reconciler, $deps] = $this->make();

        $deps['gateway']->expects($this->never())->method('checkActionStatus');
        $deps['connection']->expects($this->never())->method('update');

        $reconciler->reconcile($leg);
    }

    public function testPendingLegIsAlsoPolledAndPromoted(): void
    {
        // PENDING legs (an accepted-but-unconfirmed parent commission leg with a request id) are
        // pollable too — the loader selects QUEUED and PENDING.
        $leg = $this->leg(['state' => SplitRefund::STATE_PENDING]);
        [$reconciler, $deps] = $this->make();
        $deps['statusReader']->method('read')->willReturn(RefundStatusResult::success());
        $deps['connection']->expects($this->once())->method('update')
            ->with(
                'rkd_payu_refund',
                $this->callback(fn($bind) => $bind['state'] === SplitRefund::STATE_SUCCESS),
                $this->callback(fn($where) => $where['state IN (?)'] === [SplitRefund::STATE_QUEUED, SplitRefund::STATE_PENDING])
            )
            ->willReturn(1);

        $reconciler->reconcile($leg);
        $this->assertSame(SplitRefund::STATE_SUCCESS, $leg->getState());
    }
}
