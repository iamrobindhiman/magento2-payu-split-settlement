<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitResult;
use RKD\PayuSplit\Split\Service\SnapshotPlanReader;
use RKD\PayuSplit\Split\Service\SplitRequester;
use RKD\PayuSplit\Split\Service\SplitStateMachine;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The idempotent split requester. It fires payment_split exactly once per order
 * against the A4 snapshot and maps every PayU outcome onto the header state
 * machine: success / AGG-104 → DONE; PENDING / AGG-110 → stay (retry next run);
 * business failure → FAILED. A done header is never touched again (INV-5); a
 * missing mihpayid leaves the header where it is with a reason; a REQUESTED header
 * checks get_split_info first so a crash-retry never double-POSTs.
 */
class SplitRequesterTest extends TestCase
{
    /** @var PayuSplitClient&\PHPUnit\Framework\MockObject\MockObject */
    private $gateway;
    /** @var SnapshotPlanReader&\PHPUnit\Framework\MockObject\MockObject */
    private $planReader;
    /** @var SplitStateMachine&\PHPUnit\Framework\MockObject\MockObject */
    private $stateMachine;
    private SplitRequester $requester;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(PayuSplitClient::class);
        $this->planReader = $this->createMock(SnapshotPlanReader::class);
        $this->stateMachine = $this->createMock(SplitStateMachine::class);

        $this->requester = new SplitRequester(
            $this->gateway,
            $this->planReader,
            $this->stateMachine,
            new Json(),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param string $state
     * @param string|null $mihpayid
     * @return SplitOrder
     */
    private function header(string $state, ?string $mihpayid): SplitOrder
    {
        $header = $this->createMock(SplitOrder::class);
        $header->method('getSplitState')->willReturn($state);
        $header->method('getMihpayid')->willReturn($mihpayid);
        $header->method('getOrderId')->willReturn(45);
        return $header;
    }

    private function plan(): OrderSplitPlan
    {
        $result = new SplitResult(
            [18 => Money::fromPaise(5000)],
            Money::fromPaise(5000),
            Money::fromPaise(10000)
        );
        return new OrderSplitPlan(45, '000000045', $result, [18 => 'CHLD01']);
    }

    /**
     * @param array<string, string> $fields
     * @param mixed $raw
     * @return PayuApiResponse
     */
    private function response(array $fields, mixed $raw = []): PayuApiResponse
    {
        return PayuApiResponse::fromFields($fields, 'json', $raw);
    }

    public function testDoneHeaderIsNeverTouched(): void
    {
        $header = $this->header(SplitOrder::STATE_DONE, 'PAYU123');

        $this->gateway->expects($this->never())->method('paymentSplit');
        $this->stateMachine->expects($this->never())->method('markRequested');
        $this->stateMachine->expects($this->never())->method('markDone');

        $this->requester->request($header);
    }

    public function testMissingMihpayidRecordsReasonAndDoesNotCallGateway(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING, null);

        $this->gateway->expects($this->never())->method('paymentSplit');
        $this->stateMachine->expects($this->never())->method('markRequested');
        $this->stateMachine->expects($this->once())
            ->method('recordTransient')
            ->with($header, $this->stringContains('mihpayid'));

        $this->requester->request($header);
    }

    public function testHappyPathWritesAheadThenSplitsThenMarksDone(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->with($header)->willReturn($this->plan());

        $this->gateway->expects($this->once())
            ->method('paymentSplit')
            ->willReturn($this->response(['status' => '1']));
        // safeReadback: get_split_info pulled for audit after a successful split.
        $this->gateway->expects($this->once())
            ->method('getSplitInfo')
            ->with('PAYU123')
            ->willReturn($this->response(['status' => '1'], ['splitInfo' => ['CHLD01' => ['aggregatorSubAmt' => '50.00']]]));

        $this->stateMachine->expects($this->once())->method('markRequested')->with($header);
        $this->stateMachine->expects($this->once())
            ->method('markDone')
            ->with($header, $this->isType('string'));

        $this->requester->request($header);
    }

    public function testAgg104IsTreatedAsSuccessWithoutDoublePost(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        // AGG-104 "already split" on the POST is the idempotency signal → DONE.
        $this->gateway->expects($this->once())
            ->method('paymentSplit')
            ->willReturn($this->response(['status' => '0', 'error_code' => 'AGG-104']));
        $this->gateway->method('getSplitInfo')->willReturn($this->response(['status' => '1']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())->method('markDone');
        $this->stateMachine->expects($this->never())->method('markFailed');

        $this->requester->request($header);
    }

    public function testPendingResponseKeepsHeaderForRetry(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')->willReturn($this->response(['status' => '2']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())
            ->method('recordTransient')
            ->with($header, $this->stringContains('pending'));
        $this->stateMachine->expects($this->never())->method('markDone');
        $this->stateMachine->expects($this->never())->method('markFailed');

        $this->requester->request($header);
    }

    public function testTransientLockKeepsHeaderForRetry(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')
            ->willReturn($this->response(['status' => '0', 'error_code' => 'AGG-110']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())
            ->method('recordTransient')
            ->with($header, $this->stringContains('lock'));
        $this->stateMachine->expects($this->never())->method('markFailed');

        $this->requester->request($header);
    }

    public function testTerminalDataErrorMarksHeaderFailed(): void
    {
        // AGG-108 (sum mismatch) / AGG-103 (not splittable) / AGG-107 (bad payload) are
        // permanent — the payload/txn is structurally wrong and a retry can't fix it, so
        // the header is FAILED (terminal) and stops being scanned.
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')
            ->willReturn($this->response(['status' => '0', 'error_code' => 'AGG-108', 'message' => 'sum mismatch']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())
            ->method('markFailed')
            ->with($header, $this->stringContains('AGG-108'));
        $this->stateMachine->expects($this->never())->method('markDone');

        $this->requester->request($header);
    }

    public function testFixableConfigErrorIsTransientNotFailed(): void
    {
        // AGG-300 (hash) / AGG-101/102 (account/child config) / AGG-301 (bad payuId) are
        // fixable out-of-band — terminalising the header would silently strand the
        // money (and the whole backlog on a bad salt rotation). Keep it transient; the
        // aged-order alarm escalates it.
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')
            ->willReturn($this->response(['status' => '0', 'error_code' => 'AGG-300', 'message' => 'Hash validation failed']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())->method('recordTransient')->with($header, $this->stringContains('AGG-300'));
        $this->stateMachine->expects($this->never())->method('markFailed');
        $this->stateMachine->expects($this->never())->method('markDone');

        $this->requester->request($header);
    }

    public function testAmbiguousFailureWithoutKnownCodeIsTransient(): void
    {
        // A status-0/garbage response with no recognised terminal code must NOT
        // terminalise — the split may even have landed. Bias to transient + alarm.
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')->willReturn($this->response(['status' => '0']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())->method('recordTransient');
        $this->stateMachine->expects($this->never())->method('markFailed');

        $this->requester->request($header);
    }

    public function testRequestedHeaderWithPendingSplitDoesNotRePost(): void
    {
        // On a PayU-side PENDING split (splitStatus 'PENDING'), the KB rule is poll, do
        // NOT re-POST. The header stays REQUESTED for the next poll; payment_split is
        // never re-fired.
        $header = $this->header(SplitOrder::STATE_REQUESTED, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->expects($this->once())
            ->method('getSplitInfo')
            ->willReturn($this->response(['status' => '2', 'splitStatus' => 'PENDING']));
        $this->gateway->expects($this->never())->method('paymentSplit');

        $this->stateMachine->expects($this->once())->method('recordTransient')->with($header, $this->stringContains('pending'));
        $this->stateMachine->expects($this->never())->method('markRequested');
        $this->stateMachine->expects($this->never())->method('markDone');

        $this->requester->request($header);
    }

    public function testRecoveryReadbackSerializeFailureStillMarksDone(): void
    {
        // The recovery readback confirmed the split exists; a serialize failure on the
        // raw body (e.g. invalid UTF-8) must NOT propagate and flip a real, existing
        // split to FAILED — it degrades to a null stored readback.
        $header = $this->header(SplitOrder::STATE_REQUESTED, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        // splitStatus=success → split exists; raw carries an invalid UTF-8 byte so
        // Json::serialize throws.
        $this->gateway->method('getSplitInfo')
            ->willReturn($this->response(['status' => '1', 'splitStatus' => 'success'], ['bad' => "\xB1\x31"]));

        $this->stateMachine->expects($this->once())->method('markDone')->with($header, null);
        $this->stateMachine->expects($this->never())->method('markFailed');

        $this->requester->request($header);
    }

    public function testRequestedHeaderWithExistingSplitRecoversWithoutPost(): void
    {
        $header = $this->header(SplitOrder::STATE_REQUESTED, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        // get_split_info reports splitStatus=success → the split exists → DONE, no POST.
        $this->gateway->expects($this->once())
            ->method('getSplitInfo')
            ->with('PAYU123')
            ->willReturn($this->response(['status' => '1', 'splitStatus' => 'success']));
        $this->gateway->expects($this->never())->method('paymentSplit');

        $this->stateMachine->expects($this->once())->method('markDone')->with($header, $this->isType('string'));
        $this->stateMachine->expects($this->never())->method('markRequested');

        $this->requester->request($header);
    }

    public function testRequestedHeaderWithNoSplitYetProceedsToPost(): void
    {
        $header = $this->header(SplitOrder::STATE_REQUESTED, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        // First get_split_info has no success splitStatus → fall through to POST; second
        // is the post-success audit readback.
        $this->gateway->method('getSplitInfo')->willReturnOnConsecutiveCalls(
            $this->response(['status' => '1', 'splitStatus' => 'splitNotReceived']),
            $this->response(['status' => '1', 'splitStatus' => 'success'])
        );
        $this->gateway->expects($this->once())->method('paymentSplit')->willReturn($this->response(['status' => '1']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())->method('markDone');

        $this->requester->request($header);
    }

    public function testTokenInBodyAloneDoesNotFalselyMarkDone(): void
    {
        // A readback whose body merely echoes the tokens 'splitInfo'/'aggregatorSubAmt'
        // but does NOT report splitStatus=success must NOT be treated as an existing
        // split — otherwise the header is marked DONE while no split exists (vendors
        // never paid, INV-5 makes it immutable). It must fall through to a real POST.
        $header = $this->header(SplitOrder::STATE_REQUESTED, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('getSplitInfo')->willReturnOnConsecutiveCalls(
            $this->response(['status' => '1'], ['splitInfo' => [], 'note' => 'aggregatorSubAmt template']),
            $this->response(['status' => '1', 'splitStatus' => 'success'])
        );
        $this->gateway->expects($this->once())->method('paymentSplit')->willReturn($this->response(['status' => '1']));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())->method('markDone');

        $this->requester->request($header);
    }

    public function testTransportErrorOnPostKeepsHeaderForRecoveryNotFailed(): void
    {
        // A transport failure (network blip / PayU 5xx) is retryable, not terminal —
        // and the POST may have landed at PayU even though the response was lost. The
        // header must stay REQUESTED so the next scan's get_split_info recovery
        // reconciles, NEVER FAILED.
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')
            ->willThrowException(new PayuSplitRequestException('connection reset'));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())
            ->method('recordTransient')
            ->with($header, $this->stringContains('transport'));
        $this->stateMachine->expects($this->never())->method('markFailed');
        $this->stateMachine->expects($this->never())->method('markDone');

        $this->requester->request($header);
    }

    public function testTransportErrorOnRecoveryReadbackKeepsHeaderForRetry(): void
    {
        // The recovery get_split_info on a REQUESTED header can also transport-fail;
        // that must not terminalize the header or trigger a POST.
        $header = $this->header(SplitOrder::STATE_REQUESTED, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('getSplitInfo')
            ->willThrowException(new PayuSplitRequestException('gateway timeout'));

        $this->stateMachine->expects($this->once())
            ->method('recordTransient')
            ->with($header, $this->stringContains('transport'));
        $this->gateway->expects($this->never())->method('paymentSplit');
        $this->stateMachine->expects($this->never())->method('markRequested');
        $this->stateMachine->expects($this->never())->method('markFailed');

        $this->requester->request($header);
    }

    public function testSplitSucceedsEvenWhenReadbackFetchFails(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING, 'PAYU123');
        $this->planReader->method('read')->willReturn($this->plan());

        $this->gateway->method('paymentSplit')->willReturn($this->response(['status' => '1']));
        // The audit readback is best-effort: a transport failure here must NOT undo
        // the successful split — DONE with a null readback.
        $this->gateway->method('getSplitInfo')
            ->willThrowException(new PayuSplitRequestException('transport down'));

        $this->stateMachine->expects($this->once())->method('markRequested');
        $this->stateMachine->expects($this->once())->method('markDone')->with($header, null);

        $this->requester->request($header);
    }
}
