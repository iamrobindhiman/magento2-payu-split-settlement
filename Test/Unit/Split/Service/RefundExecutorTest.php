<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\CreditMemoRefundReader;
use RKD\PayuSplit\Split\Service\FullUnwindDetector;
use RKD\PayuSplit\Split\Service\PaidAmountReader;
use RKD\PayuSplit\Split\Service\RefundCalculator;
use RKD\PayuSplit\Split\Service\RefundExecutor;
use RKD\PayuSplit\Split\Service\RefundLedger;
use RKD\PayuSplit\Split\Service\RefundResponseParser;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentItemLoader;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refund executor is the money mover: for a cancelled vendor segment on an already
 * DONE split, it fires exactly one var8 and writes the two proven legs (E-12). These
 * tests pin the guards (Fact 4 split-must-be-DONE, ledger-presence idempotency, no
 * mihpayid / no child key), the happy-path S-05 attribution (child leg → vendor segment,
 * parent commission leg → parent segment, both bumped paise-exact), and crash safety (a
 * transport failure leaves the segment REFUND_PENDING with its deterministic token for a
 * safe retry — never a second uncoordinated POST).
 */
class RefundExecutorTest extends TestCase
{
    private RefundCalculator $calculator;
    private RefundResponseParser $parser;

    protected function setUp(): void
    {
        $this->calculator = new RefundCalculator(new Json());
        $this->parser = new RefundResponseParser();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{0: RefundExecutor, 1: array<string, object>}
     */
    private function makeExecutor(array $overrides = []): array
    {
        // The executor wraps its bookkeeping in a DB transaction; give the segment resource
        // a connection that supports begin/commit/rollBack (no-op in unit tests). A test may
        // inject its own connection mock (e.g. to assert write ordering relative to commit).
        $hasCustomConnection = isset($overrides['connection']);
        $connection = $overrides['connection'] ?? $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        unset($overrides['connection']);
        $segmentResource = $overrides['segmentResource'] ?? $this->createMock(SplitSegmentResource::class);
        $segmentResource->method('getConnection')->willReturn($connection);

        // R-1: the refund executor now claims the segment via an optimistic-CAS conditional UPDATE
        // before the POST. The default connection lets the claim win (1 row); a test asserting the
        // concurrent-loser path injects its own connection with update -> 0.
        if (!$hasCustomConnection) {
            $connection->method('update')->willReturn(1);
        }

        // The full-unwind obligation write locks the parent segment row (SELECT … FOR UPDATE) for
        // cross-tick idempotency (M-1); give the connection a chainable Select stub so the unit
        // tests exercise that path without a real DB.
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $connection->method('select')->willReturn($select);

        $deps = [
            'select' => $select,
            'gateway' => $this->createMock(PayuSplitClient::class),
            'calculator' => $this->calculator,
            'parser' => $this->parser,
            'paidReader' => $this->createMock(PaidAmountReader::class),
            'itemLoader' => $this->createMock(SegmentItemLoader::class),
            'segmentLoader' => $this->createMock(SegmentLoader::class),
            'ledger' => $this->createMock(RefundLedger::class),
            'repository' => $this->createMock(SplitOrderRepositoryInterface::class),
            'segmentResource' => $segmentResource,
            'config' => $this->createMock(SplitConfig::class),
            'fullUnwindDetector' => new FullUnwindDetector(),
            // F6 hold-awareness (holdLoader) + F5 credit-memo freeze (holdWriter, creditMemoReader).
            // Auto-mock defaults — holdTypes() → [], reader → 0 — mean "no hold, no out-of-band
            // refund", so the pre-existing happy-path tests keep firing the var8 unchanged.
            'holdLoader' => $this->createMock(SegmentHoldLoader::class),
            'holdWriter' => $this->createMock(SegmentHoldWriter::class),
            'creditMemoReader' => $this->createMock(CreditMemoRefundReader::class),
            'eventManager' => $this->createMock(\Magento\Framework\Event\ManagerInterface::class),
            'logger' => $this->createMock(LoggerInterface::class),
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }

        $executor = new RefundExecutor(
            $deps['gateway'],
            $deps['calculator'],
            $deps['parser'],
            $deps['paidReader'],
            $deps['itemLoader'],
            $deps['segmentLoader'],
            $deps['ledger'],
            $deps['repository'],
            $deps['segmentResource'],
            $deps['config'],
            $deps['fullUnwindDetector'],
            $deps['holdLoader'],
            $deps['holdWriter'],
            $deps['creditMemoReader'],
            $deps['eventManager'],
            $deps['logger']
        );
        return [$executor, $deps];
    }

    /**
     * A second, still-active vendor on the same order so a single-vendor cancel does NOT
     * read as a full unwind (the flow tests model a PARTIAL cancel like S-05).
     *
     * @param int $id
     * @param string $state
     * @return SplitSegment
     */
    private function siblingVendor(int $id = 8, string $state = SplitSegment::STATE_PENDING): SplitSegment
    {
        $sibling = $this->createMock(SplitSegment::class);
        $sibling->method('getId')->willReturn($id);
        $sibling->method('getSegmentType')->willReturn(SplitSegment::TYPE_VENDOR);
        $sibling->method('getState')->willReturn($state);
        return $sibling;
    }

    /**
     * @param array<string, mixed> $data
     * @return SplitSegment
     */
    private function vendorSegment(array $data = []): SplitSegment
    {
        $data += [
            'id' => 7, 'order_id' => 42, 'type' => SplitSegment::TYPE_VENDOR,
            'state' => SplitSegment::STATE_PENDING, 'amount' => 5000, 'refunded' => 0,
            'released' => 0, 'child_key' => 'CHLD01', 'intent_token' => null,
        ];
        $segment = $this->createMock(SplitSegment::class);
        $segment->method('getId')->willReturn($data['id']);
        $segment->method('getOrderId')->willReturn($data['order_id']);
        $segment->method('getSegmentType')->willReturn($data['type']);
        $segment->method('getState')->willReturn($data['state']);
        $segment->method('getAmountPaise')->willReturn($data['amount']);
        $segment->method('getRefundedPaise')->willReturn($data['refunded']);
        $segment->method('getReleasedPaise')->willReturn($data['released']);
        $segment->method('getChildMerchantKey')->willReturn($data['child_key']);
        $segment->method('getData')->willReturnCallback(
            static fn ($key) => $key === 'intent_token' ? $data['intent_token'] : null
        );
        return $segment;
    }

    /**
     * @param string|null $state
     * @param string|null $mihpayid
     * @return SplitOrder
     */
    private function header(?string $state = SplitOrder::STATE_DONE, ?string $mihpayid = '100000000000000004'): SplitOrder
    {
        $header = $this->createMock(SplitOrder::class);
        $header->method('getSplitState')->willReturn((string) $state);
        $header->method('getMihpayid')->willReturn($mihpayid);
        $header->method('getOrderId')->willReturn(42);
        // Shipping snapshotted at split = 11000p; the full-unwind parent (12000p commission+shipping)
        // claws its 1000p commission, leaving 11000p == shipping, so the top-up is the full shipping
        // (min(11000, 11000)) with no shortfall.
        $header->method('getShippingPaise')->willReturn(11000);
        return $header;
    }

    /**
     * @param int $amount
     * @param int $refunded
     * @return SplitSegment
     */
    private function parentSegment(int $amount = 12000, int $refunded = 0): SplitSegment
    {
        $parent = $this->createMock(SplitSegment::class);
        $parent->method('getId')->willReturn(9);
        $parent->method('getSellerId')->willReturn(0);
        $parent->method('getSegmentType')->willReturn(SplitSegment::TYPE_COMMISSION);
        $parent->method('getAmountPaise')->willReturn($amount);
        $parent->method('getRefundedPaise')->willReturn($refunded);
        return $parent;
    }

    /**
     * @param string $body
     * @return PayuApiResponse
     */
    private function refundResponse(string $body): PayuApiResponse
    {
        return (new \RKD\PayuSplit\Split\Service\PayuResponseParser(new Json()))->parse($body);
    }

    /**
     * Build a FULLY refundable vendor-segment harness — split DONE, mihpayid present, child key
     * mapped, positive customer-paid amount, no active leg — everything the executor needs to fire
     * the var8. The caller layers the ONE condition under test on top (a hold, a credit memo) and
     * configures the gateway, so a guard's absence surfaces as the var8 actually firing rather than
     * a false green from an unrelated early return. The gateway mock is left UN-stubbed for the
     * caller to either forbid (expects->never) or make fire (willReturnCallback).
     *
     * @param string[] $holds Hold types present on the segment (default none)
     * @param int $creditMemoPaise Out-of-band per-item credit-memo total the reader should report
     * @param int $orderLevelRefundPaise Out-of-band order-level (adjustment/shipping) memo total
     * @return array{0: SplitSegment, 1: array<string, object>} [segment, makeExecutor overrides]
     */
    private function refundableHarness(
        array $holds = [],
        int $creditMemoPaise = 0,
        int $orderLevelRefundPaise = 0
    ): array {
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $holdLoader = $this->createMock(SegmentHoldLoader::class);
        $holdLoader->method('holdTypes')->with(7)->willReturn($holds);

        $creditMemoReader = $this->createMock(CreditMemoRefundReader::class);
        $creditMemoReader->method('refundedPaiseForItems')->willReturn($creditMemoPaise);
        $creditMemoReader->method('orderLevelRefundPaise')->willReturn($orderLevelRefundPaise);

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->with([52])->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->with(7)->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->with(42)->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->with(42)->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->with(7)->willReturn([]);
        $ledger->method('recordLeg')->willReturn($this->createMock(SplitRefund::class));

        return [$segment, [
            'gateway' => $this->createMock(PayuSplitClient::class),
            'holdLoader' => $holdLoader,
            'creditMemoReader' => $creditMemoReader,
            'paidReader' => $paidReader,
            'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader,
            'repository' => $repository,
            'config' => $config,
            'ledger' => $ledger,
        ]];
    }

    public function testS05HappyPathFiresVar8AndWritesBothLegsBumpingBothSegments(): void
    {
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $captured = [];
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturnCallback(
            function (string $payuId, string $token, string $total, string $var8) use (&$captured) {
                $captured = compact('payuId', 'token', 'total', 'var8');
                return $this->refundResponse(
                    '{"CHLD01":{"status":1,"msg":"Refund Request Queued","request_id":"139931269","error_code":102},'
                    . '"PRNT01":{"status":1,"msg":"Refund Request Queued","request_id":"139931270","error_code":102}}'
                );
            }
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->with([52])->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->with(7)->willReturn([52]);

        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->with(42)->willReturn([$segment, $this->siblingVendor(), $parent]);

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->with(42)->willReturn($this->header());

        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->with(7)->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger,
        ]);

        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // var8 fired with the deterministic token, M-2 amounts, E-12 shape.
        $this->assertSame('100000000000000004', $captured['payuId']);
        $this->assertSame('R-7-1', $captured['token']);
        $this->assertSame('60.00', $captured['total']);
        $this->assertSame('{"CHLD01":{"amount":"50.00","aggregatorRefundAmount":"10.00"}}', $captured['var8']);

        // Two legs written: child → vendor segment (7), parent commission → parent segment (9).
        $this->assertCount(2, $legRows);
        // child leg: order 42, segment 7, amount 5000, agg 0, request_id from child key, QUEUED.
        $this->assertSame([42, 7, 5000, 0, '139931269', SplitRefund::STATE_QUEUED, SplitRefund::REASON_VENDOR_CANCEL], $legRows[0]);
        // parent leg: segment 9, amount 0, agg 1000, request_id from parent key, QUEUED.
        $this->assertSame([42, 9, 0, 1000, '139931270', SplitRefund::STATE_QUEUED, SplitRefund::REASON_VENDOR_CANCEL], $legRows[1]);
    }

    public function testBlocksWhenSplitHeaderIsNotDone(): void
    {
        // Fact 4: never var8 an unsplit transaction.
        $segment = $this->vendorSegment();
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header(SplitOrder::STATE_PENDING));

        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);

        [$executor] = $this->makeExecutor(['gateway' => $gateway, 'repository' => $repository, 'ledger' => $ledger]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testIdempotentWhenSegmentAlreadyHasAnActiveLeg(): void
    {
        // A non-FAILED leg already exists → the refund is in flight or done; never re-fire.
        $segment = $this->vendorSegment();
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->with(7)->willReturn([$this->createMock(SplitRefund::class)]);

        [$executor] = $this->makeExecutor(['gateway' => $gateway, 'ledger' => $ledger]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testSkipsNonVendorSegment(): void
    {
        $segment = $this->vendorSegment(['type' => SplitSegment::TYPE_COMMISSION]);
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        [$executor] = $this->makeExecutor(['gateway' => $gateway]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testSkipsWhenChildKeyUnmapped(): void
    {
        $segment = $this->vendorSegment(['child_key' => null]);
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);

        [$executor] = $this->makeExecutor(['gateway' => $gateway, 'repository' => $repository, 'ledger' => $ledger]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testSkipsWhenNoMihpayid(): void
    {
        $segment = $this->vendorSegment();
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header(SplitOrder::STATE_DONE, null));
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);

        [$executor] = $this->makeExecutor(['gateway' => $gateway, 'repository' => $repository, 'ledger' => $ledger]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    /**
     * F6: a FROZEN hold halts auto-processing for the segment. The recon (gate item #6) freezes a
     * segment whose refund leg FAILED at PayU after acceptance — refunded_paise was optimistically
     * bumped but the customer was NOT refunded. Nothing may re-fire a var8 against it: the books are
     * known-inconsistent and only a human may resolve it. RefundableSegments now anti-joins the hold,
     * and the executor guards in-executor too (defense in depth for any directly-passed segment).
     *
     * @return void
     */
    public function testSkipsWhenSegmentCarriesAFrozenHold(): void
    {
        // FULL happy-path harness so that WITHOUT the F6 guard the var8 WOULD fire — the FROZEN hold
        // is the only thing that must stop it (a false green would pass by returning early elsewhere).
        [$segment, $overrides] = $this->refundableHarness([SplitHold::TYPE_FROZEN]);
        $overrides['gateway']->expects($this->never())->method('cancelRefundTransaction');

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    /**
     * F6: a MANUAL operator hold likewise halts the refund executor.
     *
     * @return void
     */
    public function testSkipsWhenSegmentCarriesAManualHold(): void
    {
        [$segment, $overrides] = $this->refundableHarness([SplitHold::TYPE_MANUAL]);
        $overrides['gateway']->expects($this->never())->method('cancelRefundTransaction');

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    /**
     * F6 boundary: a CANCELED hold must NOT block the refund — the seller-portion cancel IS the
     * refund trigger, so treating it as a refund-blocking hold would strand the customer's money.
     * REFUND_BLOCKING_TYPES deliberately excludes CANCELED (and RETURN_OPEN); the var8 still fires.
     *
     * @return void
     */
    public function testCanceledHoldDoesNotBlockTheRefund(): void
    {
        [$segment, $overrides] = $this->refundableHarness([SplitHold::TYPE_CANCELED]);

        $fired = false;
        $overrides['gateway']->method('cancelRefundTransaction')->willReturnCallback(
            function () use (&$fired) {
                $fired = true;
                return $this->refundResponse(
                    '{"CHLD01":{"status":1,"msg":"Refund Request Queued","request_id":"139931269","error_code":102},'
                    . '"PRNT01":{"status":1,"msg":"Refund Request Queued","request_id":"139931270","error_code":102}}'
                );
            }
        );

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        $this->assertTrue($fired, 'A CANCELED hold must not block the refund var8.');
    }

    /**
     * F5: an out-of-band per-item Magento credit memo (sales_order_item.base_amount_refunded > 0)
     * means the customer was already refunded OUTSIDE the split engine. The var8 nets
     * base_amount_refunded, which Magento writes tax-EXCLUSIVE (tax → base_tax_refunded, discount →
     * base_discount_refunded), so netting through it would still re-refund the line's GST — and
     * release_settlement has no amount param to claw it back. Mirror the release-side #5/F3 posture:
     * FREEZE (durable TYPE_FROZEN hold + CRITICAL), never net. Composes with F6 (the hold drops the
     * segment from the scan + the in-executor guard).
     *
     * @return void
     */
    public function testFreezesWhenPerItemCreditMemoRefundExists(): void
    {
        [$segment, $overrides] = $this->refundableHarness([], 6000, 0);
        $overrides['gateway']->expects($this->never())->method('cancelRefundTransaction');

        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_FROZEN, $this->anything());
        $overrides['holdWriter'] = $holdWriter;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');
        $overrides['logger'] = $logger;

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    /**
     * F5 (F3 extension): an order-level out-of-band refund (adjustment/shipping credit memo) touches
     * no item row, so the per-item read misses it — the executor must also consult the order-level
     * sum and freeze on any non-zero total. Same FROZEN-hold posture, no var8.
     *
     * @return void
     */
    public function testFreezesWhenOrderLevelCreditMemoRefundExists(): void
    {
        [$segment, $overrides] = $this->refundableHarness([], 0, 500);
        $overrides['gateway']->expects($this->never())->method('cancelRefundTransaction');

        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        $holdWriter->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_FROZEN, $this->anything());
        $overrides['holdWriter'] = $holdWriter;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');
        $overrides['logger'] = $logger;

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    /**
     * F7: PayU signalled acceptance (status:1) for the CHILD leg but issued NO request_id — the
     * poll/idempotency anchor. This is a var2-dedup echo of a token retry ("already accepted"
     * without a fresh request_id, H-6). Recording it QUEUED would bump refunded_paise for a leg the
     * reconciler can never poll (RefundLegLoader excludes null-request-id) and never alarm
     * (RefundReconciler early-returns on null request_id) — a silent strand that still blocks
     * release. And it is NOT a rejection (re-firing could double-refund a real PayU debit). Halt:
     * freeze the segment (HELD marker, NO bump, NO QUEUED leg) for manual reconciliation.
     *
     * @return void
     */
    public function testFreezesWhenAcceptedChildLegHasNoRequestId(): void
    {
        [$segment, $overrides] = $this->refundableHarness();
        // Child (CHLD01) accepted but request_id ABSENT → parsed as null; parent (PRNT01) normal.
        $overrides['gateway']->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"msg":"Refund Request Queued","error_code":102},'
                . '"PRNT01":{"status":1,"msg":"Refund Request Queued","request_id":"139931270","error_code":102}}'
            )
        );

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->with(7)->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );
        $overrides['ledger'] = $ledger;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');
        $overrides['logger'] = $logger;

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Exactly one leg — the HELD freeze marker on the vendor segment. No QUEUED child/parent
        // leg was written (the freeze returns before the bookkeeping transaction) → nothing bumped.
        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    /**
     * F7 (parent leg): the commission leg was accepted with no request_id — the same silent-strand
     * risk on the PARENT segment. Freeze the whole var8 response (no legs recorded, no bump); a human
     * reconciles against PayU's actual state. The conservative R-7-class halt.
     *
     * @return void
     */
    public function testFreezesWhenAcceptedParentLegHasNoRequestId(): void
    {
        [$segment, $overrides] = $this->refundableHarness();
        // Child (CHLD01) normal; parent (PRNT01) accepted but request_id ABSENT → parsed as null.
        $overrides['gateway']->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"msg":"Refund Request Queued","request_id":"139931269","error_code":102},'
                . '"PRNT01":{"status":1,"msg":"Refund Request Queued","error_code":102}}'
            )
        );

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->with(7)->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );
        $overrides['ledger'] = $ledger;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');
        $overrides['logger'] = $logger;

        [$executor] = $this->makeExecutor($overrides);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Whole response discarded — one HELD marker only, no QUEUED legs, no bump.
        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    public function testTransportFailureLeavesSegmentPendingWithTokenAndWritesNoLeg(): void
    {
        // A POST may have landed though the response was lost — never write a leg on a
        // transport error; leave REFUND_PENDING + the deterministic token for a safe retry.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')
            ->willThrowException(new PayuSplitRequestException('Connection timed out'));

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->expects($this->never())->method('recordLeg');

        // The write-ahead persisted REFUND_PENDING + token before the POST.
        $segment->expects($this->atLeastOnce())->method('setState')->with(SplitSegment::STATE_REFUND_PENDING);

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config, 'ledger' => $ledger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testBusinessRejectionWritesFailedLegForOperator(): void
    {
        // PayU rejects the refund (no accepted child leg) → record a FAILED leg so the
        // operator/recon sees it; never bump the segment as refunded.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse('{"CHLD01":{"status":0,"msg":"Refund failed","error_code":"E101"}}')
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config, 'ledger' => $ledger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Both legs failed: the rejected child FAILED leg, and the unconfirmed parent
        // commission FAILED leg — neither bumps a segment.
        $this->assertCount(2, $legRows);
        $this->assertSame(SplitRefund::STATE_FAILED, $legRows[0][5]);
        $this->assertSame(SplitRefund::STATE_FAILED, $legRows[1][5]);
    }

    public function testChildRejectedButParentAcceptedRecordsParentAndAlarmsMismatch(): void
    {
        // PayU rejected the vendor (child) leg but accepted the parent commission leg — the
        // commission refund really happened, so it must be recorded + bumped (not silently
        // dropped, which would later over-refund), and the mismatch must alarm CRITICAL.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":0,"msg":"Refund failed","error_code":"E101"},'
                . '"PRNT01":{"status":1,"request_id":"139931270","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Child FAILED, parent QUEUED (the commission refund landed → recorded + bumped).
        $this->assertCount(2, $legRows);
        $this->assertSame(SplitRefund::STATE_FAILED, $legRows[0][5]); // child
        $this->assertSame([42, 9, 0, 1000, '139931270', SplitRefund::STATE_QUEUED, SplitRefund::REASON_VENDOR_CANCEL], $legRows[1]);
    }

    public function testParentLegMissingWhenChildAcceptedIsRecordedPendingForRecon(): void
    {
        // R-5: the child leg is accepted but PayU's response carries no parent-commission leg.
        // The vendor refund is real (record + bump it), but the parent commission portion is
        // UNCONFIRMED — it must NOT bump the parent's refunded_paise. Because the child now has
        // a QUEUED leg, the vendor segment is excluded from the scan forever, so a FAILED
        // parent leg would sit on NO retry surface (commission permanently under-refunded).
        // Record the unconfirmed parent leg as PENDING instead — a queryable recon/retry
        // surface — and alarm. Never bump the parent.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            // Only the child leg comes back accepted; the parent (PRNT01) leg is absent.
            $this->refundResponse('{"CHLD01":{"status":1,"request_id":"139931269","error_code":102}}')
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        // The parent segment must never be touched when its leg is unconfirmed.
        $parent->expects($this->never())->method('setState');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Child leg recorded QUEUED → vendor segment; parent leg recorded PENDING (not FAILED)
        // so the unconfirmed commission stays on a recon/retry surface, and the parent is not
        // bumped.
        $this->assertCount(2, $legRows);
        $this->assertSame([42, 7, 5000, 0, '139931269', SplitRefund::STATE_QUEUED, SplitRefund::REASON_VENDOR_CANCEL], $legRows[0]);
        $this->assertSame(9, $legRows[1][1]); // PENDING parent leg → parent segment
        $this->assertSame(1000, $legRows[1][3]); // aggregator paise
        $this->assertSame(SplitRefund::STATE_PENDING, $legRows[1][5]);
    }

    public function testRefusesToRefundAnAlreadyReleasedSegment(): void
    {
        // The vendor's escrow has been released (paid out) — a var8 against it would refund
        // money the marketplace no longer holds. Refuse and alarm; never fire.
        $segment = $this->vendorSegment(['state' => SplitSegment::STATE_RELEASED, 'released' => 5000]);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $ledger = $this->createMock(RefundLedger::class);
        $ledger->expects($this->never())->method('activeLegs');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        [$executor] = $this->makeExecutor(['gateway' => $gateway, 'ledger' => $ledger, 'logger' => $logger]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testRefusesWhenChildKeyEqualsParentAggregatorKey(): void
    {
        // A submerchant mis-mapped to the parent key would make both legs resolve to the
        // same response entry — refuse rather than mis-attribute.
        $segment = $this->vendorSegment(['child_key' => 'PRNT01']);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'repository' => $repository, 'ledger' => $ledger, 'config' => $config,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testReusesStoredIntentTokenOnRetry(): void
    {
        // A crash-recovery re-fire reuses the deterministic token persisted at write-ahead
        // (PayU's var2 uniqueness then dedups the duplicate POST).
        $segment = $this->vendorSegment(['state' => SplitSegment::STATE_REFUND_PENDING, 'intent_token' => 'R-7-1']);
        $parent = $this->parentSegment();

        $captured = [];
        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturnCallback(
            function (string $payuId, string $token, string $total, string $var8) use (&$captured) {
                $captured['token'] = $token;
                return $this->refundResponse(
                    '{"CHLD01":{"status":1,"request_id":"1","error_code":102},'
                    . '"PRNT01":{"status":1,"request_id":"2","error_code":102}}'
                );
            }
        );
        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturn($this->createMock(SplitRefund::class));

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config, 'ledger' => $ledger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        $this->assertSame('R-7-1', $captured['token']);
    }

    public function testFullUnwindRecordsPendingShippingObligationAndAlarms(): void
    {
        // The only vendor on the order cancels → the order fully unwinds. Beyond the normal
        // vendor refund, the customer is owed the retained shipping (the parent's remaining
        // balance after the commission refund). That cannot ride the var8 (per-child agg
        // cap), so it is recorded as a PENDING FULL_UNWIND obligation on the parent (which
        // holds the parent's release) and alarmed — never silently stranded.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"request_id":"139931269","error_code":102},'
                . '"PRNT01":{"status":1,"request_id":"139931270","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        // Single vendor on the order — no surviving sibling.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        // Model reality: by the time the full-unwind check runs, the parent already has its
        // own commission leg (reason VENDOR_CANCEL). That must NOT be mistaken for an
        // existing FULL_UNWIND obligation — the obligation is still recorded. (This is the
        // exact bug the live sandbox smoke caught.)
        $commissionLeg = $this->createMock(SplitRefund::class);
        $commissionLeg->method('getReason')->willReturn(SplitRefund::REASON_VENDOR_CANCEL);

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturnCallback(
            static fn (int $id) => $id === 9 ? [$commissionLeg] : []
        );
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // child QUEUED, parent QUEUED, then the PENDING shipping obligation on the parent.
        $this->assertCount(3, $legRows);
        // [orderId, parentId, amount=0, agg=parent remaining after refund (12000-1000), null, PENDING, FULL_UNWIND]
        $this->assertSame([42, 9, 0, 11000, null, SplitRefund::STATE_PENDING, SplitRefund::REASON_FULL_UNWIND], $legRows[2]);
    }

    public function testFullUnwindObligationIsRecordedInsideTheTransaction(): void
    {
        // R-4: the full-unwind shipping obligation is a DB write. If it runs AFTER commit(),
        // a crash in that window strands the customer's shipping silently (no obligation row →
        // the parent's release is never held). It must be written INSIDE the same transaction
        // as the refund legs, so it is atomic with them — recorded BEFORE commit().
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"request_id":"139931269","error_code":102},'
                . '"PRNT01":{"status":1,"request_id":"139931270","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        // Single vendor on the order → refunding it fully unwinds the order.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $commissionLeg = $this->createMock(SplitRefund::class);
        $commissionLeg->method('getReason')->willReturn(SplitRefund::REASON_VENDOR_CANCEL);

        // One ordered event log shared by the ledger and the connection mock: every leg push
        // records its reason; commit() pushes a 'commit' marker. The FULL_UNWIND leg must
        // appear before 'commit'.
        $events = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturnCallback(
            static fn (int $id) => $id === 9 ? [$commissionLeg] : []
        );
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$events) {
                $events[] = 'leg:' . $args[6]; // arg 6 = reason
                return $this->createMock(SplitRefund::class);
            }
        );

        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('update')->willReturn(1); // R-1 CAS claim wins
        $connection->method('commit')->willReturnCallback(
            function () use (&$events) {
                $events[] = 'commit';
                return 1;
            }
        );

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'connection' => $connection,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        $obligationIdx = array_search('leg:' . SplitRefund::REASON_FULL_UNWIND, $events, true);
        $commitIdx = array_search('commit', $events, true);
        $this->assertNotFalse($obligationIdx, 'the full-unwind obligation must be recorded');
        $this->assertNotFalse($commitIdx, 'the transaction must commit');
        $this->assertLessThan(
            $commitIdx,
            $obligationIdx,
            'R-4: the full-unwind obligation must be written INSIDE the transaction (before commit)'
        );
    }

    public function testHaltsAndFreezesWhenResponseCarriesAnUnexpectedMerchantLeg(): void
    {
        // R-7: the var8 fired and PayU's response carries a merchant key we never targeted (a
        // real debit we cannot attribute) alongside the expected child + parent. The normal
        // bookkeeping trusts our local plan and silently IGNORES the stray key — so it must
        // NOT run. Freeze the vendor segment off the retry surface with a HELD marker leg (so
        // the scan never re-fires an anomalous transaction → double-refund) and alarm CRITICAL
        // for manual reconciliation. No QUEUED legs, no bump.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"request_id":"1","error_code":102},'
                . '"PRNT01":{"status":1,"request_id":"2","error_code":102},'
                . '"MYST3RY":{"status":1,"request_id":"3","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        // Neither segment may be bumped — nothing is confirmed-attributed.
        $parent->expects($this->never())->method('setState');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Exactly one leg written: the HELD freeze marker on the vendor segment (7). No QUEUED
        // child/parent legs — the anomalous response is not interpreted.
        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);          // freeze marker on the vendor segment
        $this->assertSame(0, $legRows[0][2]);          // amount 0 (pure marker)
        $this->assertSame(0, $legRows[0][3]);          // agg 0 (pure marker)
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    public function testHaltsAndFreezesWhenStrayLegArrivesUnderANumericMerchantKey(): void
    {
        // R-7 hole (found by adversarial review): a stray debit returned under a *numeric*
        // PayU merchant key is coerced by json_decode to an int key, which the leg parser drops
        // before the anomaly check ever sees it — so without inspecting the RAW body the guard
        // misses it and the normal bookkeeping silently ignores a real third debit. The guard
        // must read the raw decoded body and treat a numeric-keyed leg as the anomaly it is.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"request_id":"1","error_code":102},'
                . '"PRNT01":{"status":1,"request_id":"2","error_code":102},'
                . '"991234":{"status":1,"request_id":"3","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $parent->expects($this->never())->method('setState');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Exactly one leg: the HELD freeze marker on the vendor segment (7). No QUEUED legs —
        // the numeric-keyed stray debit is caught and the response is not interpreted.
        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    public function testRetryCapFreezesAfterTooManyFailedAttempts(): void
    {
        // R-6: a refund PayU keeps rejecting must not loop forever (re-firing every tick AND
        // hammering PayU — a rejected child leg is FAILED, which the scan re-selects). Once the
        // segment has accumulated MAX_REFUND_ATTEMPTS FAILED legs, stop firing — freeze it off
        // the scan with a HELD marker + CRITICAL alarm for a human. No var8 is sent.
        $segment = $this->vendorSegment(['state' => SplitSegment::STATE_REFUND_PENDING]);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('failedLegCount')->with(7)->willReturn(RefundExecutor::MAX_REFUND_ATTEMPTS);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        // The cap is checked before Fact 4 / the PayU call, so no repository/gateway stubs are
        // needed: if the guard were missing the flow would fall through and NOT freeze.
        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Exactly one leg: the HELD freeze marker on the vendor segment (7). No var8.
        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    public function testNegativePaidAmountFreezesInsteadOfLoopingSilently(): void
    {
        // B1: a negative prorated paid amount (Magento over-allocated discount) is a data error
        // that will not self-resolve. Previously it only logged + returned, so the scan
        // re-selected the segment every tick (silent loop, no queryable row). Now it FREEZES
        // (HELD marker + alarm): queryable, off the scan, for a human to fix the allocation.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(-100);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    public function testUnfundableRefundFreezesInsteadOfLoopingSilently(): void
    {
        // B1: when the refund math cannot be funded (paid exceeds segment remaining + parent
        // remaining → RefundCalculationException), retrying every tick is a silent loop that
        // never refunds the customer. FREEZE (HELD + alarm) so the stuck refund is queryable.
        $segment = $this->vendorSegment();            // segment remaining = 5000
        $parent = $this->parentSegment(12000, 11500); // parent remaining = 500

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000); // 6000 > 5000 + 500 → unfundable
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        $this->assertCount(1, $legRows);
        $this->assertSame(7, $legRows[0][1]);
        $this->assertSame(SplitRefund::STATE_HELD, $legRows[0][5]);
    }

    public function testZeroPaidLineTerminalizesWithoutFiringVar8(): void
    {
        // A fully-comped line (customer paid nothing) has nothing to refund — terminalise the
        // segment so it neither loops on the scan nor fires a var8. Here a sibling vendor is
        // still live, so the order has NOT fully unwound: no shipping obligation is recorded.
        $segment = $this->vendorSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(0);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        // A surviving sibling vendor (PENDING) → not a full unwind.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $this->parentSegment()]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        // Not a full unwind → no obligation leg of any kind.
        $ledger->expects($this->never())->method('recordLeg');

        // The segment is marked REFUNDED (nothing owed).
        $segment->expects($this->atLeastOnce())->method('setState')->with(SplitSegment::STATE_REFUNDED);

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config, 'ledger' => $ledger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testCompedSegmentCompletingFullUnwindRecordsShippingObligation(): void
    {
        // Gate blocker #8: a fully-comped segment can be the LAST vendor to terminalise,
        // completing a full unwind. The customer still paid shipping, which the parent retains.
        // The comped branch must record that FULL_UNWIND shipping obligation (PENDING leg on the
        // parent) + alarm — exactly as the var8 path does — or the shipping is silently stranded
        // and the parent later over-releases it. No var8 fires (nothing to refund on this line).
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(0);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        // Single vendor on the order → this comped segment completes the unwind.
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $legRows = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$legRows) {
                $legRows[] = $args;
                return $this->createMock(SplitRefund::class);
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('critical');

        $segment->expects($this->atLeastOnce())->method('setState')->with(SplitSegment::STATE_REFUNDED);

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // Exactly one leg: the PENDING shipping obligation on the parent. No var8 legs (nothing
        // was paid on the comped line). topUp = min(shipping 11000, parentRemaining 12000) = 11000.
        $this->assertCount(1, $legRows);
        $this->assertSame(
            [42, 9, 0, 11000, null, SplitRefund::STATE_PENDING, SplitRefund::REASON_FULL_UNWIND],
            $legRows[0]
        );
    }

    public function testFullUnwindObligationTakesParentRowLockBeforeWriting(): void
    {
        // M-1: two overlapping refund ticks detecting the SAME order's full unwind must not both
        // write a FULL_UNWIND obligation. The fix serializes them by locking the parent segment row
        // (SELECT … FOR UPDATE) inside the transaction BEFORE the idempotency re-check + the write.
        // We can't exercise real row contention in a unit test, but we can assert the lock query is
        // issued (forUpdate) and that it happens BEFORE the obligation leg is recorded (so a second
        // tick would block on it and then see the committed leg).
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(0);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $events = [];
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturnCallback(
            function (...$args) use (&$events) {
                $events[] = 'leg:' . $args[6];
                return $this->createMock(SplitRefund::class);
            }
        );

        // Custom connection records the FOR UPDATE lock query (fetchOne) in the shared event log.
        // makeExecutor supplies the chainable Select stub (select()->from()->where()->forUpdate()).
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('update')->willReturn(1); // R-1 CAS claim (comped path doesn't fire it, but harmless)
        $connection->method('fetchOne')->willReturnCallback(
            function () use (&$events) {
                $events[] = 'lock';
                return '9';
            }
        );

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'connection' => $connection,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        $lockIdx = array_search('lock', $events, true);
        $obligationIdx = array_search('leg:' . SplitRefund::REASON_FULL_UNWIND, $events, true);
        $this->assertNotFalse($lockIdx, 'the parent row must be locked (SELECT … FOR UPDATE)');
        $this->assertNotFalse($obligationIdx, 'the full-unwind obligation must be recorded');
        $this->assertLessThan(
            $obligationIdx,
            $lockIdx,
            'M-1: the parent lock must be taken BEFORE the idempotency check + obligation write'
        );
    }

    public function testHappyPathDispatchesSegmentRefundedEvent(): void
    {
        // Gate blocker #4: once the customer is refunded the vendor is owed nothing, so the
        // executor dispatches rkd_payu_segment_refunded (post-commit) carrying the just-refunded
        // vendor segment, so the Webkul ledger close (non-payable saleslist) can fire.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"request_id":"139931269","error_code":102},'
                . '"PRNT01":{"status":1,"request_id":"139931270","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturn($this->createMock(SplitRefund::class));

        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $eventManager->expects($this->once())->method('dispatch')
            ->with('rkd_payu_segment_refunded', ['segment' => $segment]);

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'eventManager' => $eventManager,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testCompedSegmentDispatchesSegmentRefundedEvent(): void
    {
        // A fully-comped vendor segment is terminalised REFUNDED (no var8) — it must still be
        // closed in Webkul (its saleslist rows are payable from the original invoice), so the
        // event fires on the comped path too.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(0);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);

        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $eventManager->expects($this->once())->method('dispatch')
            ->with('rkd_payu_segment_refunded', ['segment' => $segment]);

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'eventManager' => $eventManager,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testTransportFailureDoesNotDispatchRefundedEvent(): void
    {
        // The segment stays REFUND_PENDING (the var8 may have landed but the response was lost) —
        // nothing is refunded yet, so the Webkul close must NOT fire.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')
            ->willThrowException(new PayuSplitRequestException('Connection timed out'));

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);

        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $eventManager->expects($this->never())->method('dispatch');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'eventManager' => $eventManager,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testRejectedChildDoesNotDispatchRefundedEvent(): void
    {
        // PayU rejected the vendor (child) leg → the segment is NOT bumped and stays
        // REFUND_PENDING, so the Webkul close must NOT fire (the vendor is still owed nothing
        // only once the refund actually lands).
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse('{"CHLD01":{"status":0,"msg":"Refund failed","error_code":"E101"}}')
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturn($this->createMock(SplitRefund::class));

        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $eventManager->expects($this->never())->method('dispatch');

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'eventManager' => $eventManager,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testConcurrentRefundClaimLostBailsBeforePost(): void
    {
        // R-1: an overlapping cron tick already claimed this segment, so the optimistic-CAS
        // conditional UPDATE matches 0 rows. The executor MUST bail before the POST — no var8, no
        // leg, no bump — so the customer is never double-refunded.
        $segment = $this->vendorSegment();
        $parent = $this->parentSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');

        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->expects($this->never())->method('recordLeg');

        // The CAS claim loses (0 rows affected).
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('update')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info')->with($this->stringContains('already claimed for refund'));

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'connection' => $connection, 'logger' => $logger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }

    public function testClaimUsesObservedVersionAndParentBumpIsAtomicIncrement(): void
    {
        // R-1: the write-ahead is an optimistic-CAS claim — a conditional UPDATE that sets
        // state=REFUND_PENDING + bumps refund_attempts, gated on the version we read
        // (refund_attempts = :observed), so a concurrent tick that reads the same version loses.
        // R-2: the SHARED parent commission bump is an ATOMIC INCREMENT (refunded_paise = refunded_
        // paise + delta), never a read-modify-write save, so two vendors of the same order refunding
        // cannot lose-update the parent's cumulative refund.
        $segment = $this->vendorSegment();
        $segment->method('getRefundAttempts')->willReturn(3); // the observed version
        $parent = $this->parentSegment(12000, 0);

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->method('cancelRefundTransaction')->willReturn(
            $this->refundResponse(
                '{"CHLD01":{"status":1,"request_id":"139931269","error_code":102},'
                . '"PRNT01":{"status":1,"request_id":"139931270","error_code":102}}'
            )
        );

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(6000);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->siblingVendor(), $parent]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);
        $ledger->method('recordLeg')->willReturn($this->createMock(SplitRefund::class));

        $updates = [];
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('update')->willReturnCallback(function ($table, $bind, $where) use (&$updates) {
            $updates[] = ['bind' => $bind, 'where' => $where];
            return 1;
        });

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config,
            'ledger' => $ledger, 'connection' => $connection,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);

        // The CAS claim: state=REFUND_PENDING, gated on the observed version, attempts incremented.
        $claim = null;
        $parentBump = null;
        foreach ($updates as $u) {
            if (($u['bind']['state'] ?? null) === SplitSegment::STATE_REFUND_PENDING) {
                $claim = $u;
            }
            if (array_key_exists('refunded_paise', $u['bind'])) {
                $parentBump = $u;
            }
        }
        $this->assertNotNull($claim, 'the refund write-ahead must be a CAS claim (state=REFUND_PENDING)');
        $this->assertSame(7, $claim['where']['segment_id = ?']);
        $this->assertSame(3, $claim['where']['refund_attempts = ?'], 'claim must be gated on the observed version');
        $this->assertInstanceOf(\Magento\Framework\DB\Sql\Expression::class, $claim['bind']['refund_attempts']);

        // R-2: the parent (segment 9) refunded_paise is bumped via an atomic SQL increment.
        $this->assertNotNull($parentBump, 'the parent commission bump must be an atomic increment UPDATE');
        $this->assertSame(9, $parentBump['where']['segment_id = ?']);
        $this->assertInstanceOf(\Magento\Framework\DB\Sql\Expression::class, $parentBump['bind']['refunded_paise']);
    }
}
