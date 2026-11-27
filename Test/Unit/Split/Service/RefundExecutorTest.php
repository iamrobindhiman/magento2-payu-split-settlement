<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\FullUnwindDetector;
use RKD\PayuSplit\Split\Service\PaidAmountReader;
use RKD\PayuSplit\Split\Service\RefundCalculator;
use RKD\PayuSplit\Split\Service\RefundExecutor;
use RKD\PayuSplit\Split\Service\RefundLedger;
use RKD\PayuSplit\Split\Service\RefundResponseParser;
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
        $connection = $overrides['connection'] ?? $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        unset($overrides['connection']);
        $segmentResource = $overrides['segmentResource'] ?? $this->createMock(SplitSegmentResource::class);
        $segmentResource->method('getConnection')->willReturn($connection);

        $deps = [
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
        // segment so it neither loops on the scan nor blocks a full-unwind, and never fire.
        $segment = $this->vendorSegment();

        $gateway = $this->createMock(PayuSplitClient::class);
        $gateway->expects($this->never())->method('cancelRefundTransaction');

        $paidReader = $this->createMock(PaidAmountReader::class);
        $paidReader->method('paidPaiseForItems')->willReturn(0);
        $itemLoader = $this->createMock(SegmentItemLoader::class);
        $itemLoader->method('loadOrderItemIds')->willReturn([52]);
        $segmentLoader = $this->createMock(SegmentLoader::class);
        $segmentLoader->method('loadByOrderId')->willReturn([$segment, $this->parentSegment()]);
        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->method('getByOrderId')->willReturn($this->header());
        $config = $this->createMock(SplitConfig::class);
        $config->method('getMerchantKey')->willReturn('PRNT01');
        $ledger = $this->createMock(RefundLedger::class);
        $ledger->method('activeLegs')->willReturn([]);

        // The segment is marked REFUNDED (nothing owed).
        $segment->expects($this->atLeastOnce())->method('setState')->with(SplitSegment::STATE_REFUNDED);

        [$executor] = $this->makeExecutor([
            'gateway' => $gateway, 'paidReader' => $paidReader, 'itemLoader' => $itemLoader,
            'segmentLoader' => $segmentLoader, 'repository' => $repository, 'config' => $config, 'ledger' => $ledger,
        ]);
        $executor->execute($segment, SplitRefund::REASON_VENDOR_CANCEL);
    }
}
