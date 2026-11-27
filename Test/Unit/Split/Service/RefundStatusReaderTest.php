<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Service\RefundStatusReader;
use PHPUnit\Framework\TestCase;

/**
 * Interprets a check_action_status_txnid response into a refund-leg status
 * (SUCCESS / FAILURE / PROCESSING / NONE). Conservative by design: an absent or unrecognized
 * status is NONE (no action — the reconciler waits / strand-alarms), NEVER a guessed promotion.
 * The exact field + value names are an O-11 prod-verify calibration (UAT returns a generic error,
 * E-12), so the only safe failure mode is "no signal", not a wrong SUCCESS/FAILURE.
 */
class RefundStatusReaderTest extends TestCase
{
    private function resp(mixed $raw): PayuApiResponse
    {
        return PayuApiResponse::fromFields([], 'json', $raw);
    }

    public function testRecognisesSuccess(): void
    {
        $r = (new RefundStatusReader())->read($this->resp(['status' => 'SUCCESS']));
        $this->assertTrue($r->isSuccess());
        $this->assertTrue($r->hasSignal());
        $this->assertFalse($r->isFailure());
    }

    public function testRecognisesFailureIncludingOdHit(): void
    {
        $reader = new RefundStatusReader();
        $this->assertTrue($reader->read($this->resp(['status' => 'failure']))->isFailure());
        $this->assertTrue($reader->read($this->resp(['status' => 'od_hit']))->isFailure());
    }

    public function testRecognisesProcessingStates(): void
    {
        $reader = new RefundStatusReader();
        foreach (['queued', 'requested', 'in progress', 'pending'] as $s) {
            $this->assertTrue($reader->read($this->resp(['status' => $s]))->isProcessing(), $s);
        }
    }

    public function testCaseAndWhitespaceInsensitive(): void
    {
        $this->assertTrue((new RefundStatusReader())->read($this->resp(['status' => '  Success  ']))->isSuccess());
    }

    public function testUnknownStatusValueIsNoSignalNotAGuess(): void
    {
        // A present-but-unrecognized value must NEVER be guessed into success/failure — the
        // reconciler must wait, not act on a value we don't understand.
        $r = (new RefundStatusReader())->read($this->resp(['status' => 'weird_new_state']));
        $this->assertFalse($r->hasSignal());
        $this->assertFalse($r->isSuccess());
        $this->assertFalse($r->isFailure());
    }

    public function testPaymentSettlementVocabularyIsNotRefundSuccess(): void
    {
        // 'paid' / 'settled' / 'completed' are PAYMENT-settlement vocabulary — on a REFUND-status
        // endpoint they can echo the parent PAYMENT's status (esp. nested under 'transaction_details'),
        // not the refund's. Treating any as refund-SUCCESS would falsely promote a still-in-flight
        // refund → and gate item #12 makes a false SUCCESS release-TRIGGERING (it unblocks the
        // remainder's release), so these must read as NONE (wait / strand-alarm) until O-11 confirms
        // the real refund-status vocabulary against a production body. (#21 — fail-safe: O-11 re-adds
        // any token it can confirm.)
        foreach (['paid', 'settled', 'completed'] as $paymentEcho) {
            $r = (new RefundStatusReader())->read($this->resp(['status' => $paymentEcho]));
            $this->assertFalse($r->isSuccess(), $paymentEcho . ' must not be a refund SUCCESS');
            $this->assertFalse($r->hasSignal(), $paymentEcho . ' must read as no-signal');
        }
    }

    public function testNumericStatusIsNoSignal(): void
    {
        // status:1/0 is ambiguous for split (E-07) — never treat a bare number as authoritative.
        $this->assertFalse((new RefundStatusReader())->read($this->resp(['status' => 1]))->hasSignal());
    }

    public function testNoStatusFieldIsNoSignal(): void
    {
        $this->assertFalse((new RefundStatusReader())->read($this->resp(['foo' => 'bar']))->hasSignal());
    }

    public function testNonArrayBodyIsNoSignal(): void
    {
        // UAT returns a print_r/error string, not a decodable map.
        $this->assertFalse((new RefundStatusReader())->read($this->resp('some error string'))->hasSignal());
    }

    public function testAlternateStatusFieldName(): void
    {
        $this->assertTrue((new RefundStatusReader())->read($this->resp(['refund_status' => 'SUCCESS']))->isSuccess());
    }

    public function testStatusUnderAKnownWrapperKey(): void
    {
        // Some PayU responses nest the payload under result/data — probe known wrappers only
        // (never a blind deep scan, which could read an unrelated status field).
        $r = (new RefundStatusReader())->read($this->resp(['result' => ['status' => 'SUCCESS']]));
        $this->assertTrue($r->isSuccess());
    }
}
