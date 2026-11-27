<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\RefundStatusResult;

/**
 * Reads a refund leg's status from a check_action_status_txnid response (gate item #6), the poll
 * that promotes a QUEUED/PENDING refund leg to SUCCESS or FAILED. Pure: a decoded response in, a
 * RefundStatusResult out.
 *
 * ── PROD-VERIFY (O-11) — THIS FILE IS THE ONE CALIBRATION SURFACE ──
 * PayU UAT exposes no refund lifecycle (E-12): check_action_status_txnid returns a generic error
 * in sandbox, so this reader returns noSignal() there and the recon is a safe no-op (a leg never
 * wrongly flips). The exact production status field name and value vocabulary could not be
 * observed. The candidate lists below target the documented states (QUEUED / REQUESTED /
 * IN PROGRESS / SUCCESS / FAILURE / od_hit). The discriminator is deliberately CONSERVATIVE: an
 * unrecognized value, a bare number (status:1/0 is ambiguous per E-07), an absent field, or a
 * non-array body all read as NONE — the reconciler then waits / strand-alarms rather than guessing
 * a promotion. The only safe wrong answer is "no signal", never a wrong SUCCESS/FAILURE.
 *
 * At O-11, capture a real check_action_status_txnid body for a refunded leg and calibrate the four
 * constant lists below to the real names. No other file changes for that calibration.
 */
class RefundStatusReader
{
    /** Top-level (or known-wrapper) fields that may carry the textual refund status. */
    private const STATUS_FIELDS = ['status', 'refund_status', 'action_status', 'transaction_status', 'txn_status'];

    /** Known wrapper keys under which the payload object may nest (never a blind deep scan). */
    private const WRAPPER_KEYS = ['result', 'data', 'transaction_details', 'response'];

    /**
     * Normalized status values that mean the refund COMPLETED. Deliberately EXCLUDES the
     * PAYMENT-settlement vocabulary 'paid' / 'settled' / 'completed' — on a refund-status endpoint
     * those tokens are ambiguous (they can echo the parent PAYMENT's status, esp. nested under the
     * 'transaction_details' wrapper), so treating any as a refund SUCCESS could falsely promote a
     * still-in-flight leg. Since gate item #12 makes a false SUCCESS release-TRIGGERING (a SUCCESS
     * leg stops blocking the remainder's release), these must fail safe: they read as NONE (wait /
     * strand-alarm) until O-11 confirms the REAL refund-SUCCESS vocabulary against a production
     * check_action_status_txnid body — at which point any confirmed token is re-added here (#21).
     */
    private const SUCCESS_VALUES = ['success', 'refunded', 'refund successful'];

    /** Normalized status values that mean the refund FAILED / was rejected. */
    private const FAILURE_VALUES = ['failure', 'failed', 'rejected', 'declined', 'error', 'od_hit'];

    /** Normalized status values that mean the refund is still IN FLIGHT. */
    private const PROCESSING_VALUES = [
        'queued',
        'requested',
        'in progress',
        'in_progress',
        'pending',
        'initiated',
        'processing',
    ];

    /**
     * @param PayuApiResponse $response The check_action_status_txnid response
     * @return RefundStatusResult noSignal() unless a recognized status value is present
     */
    public function read(PayuApiResponse $response): RefundStatusResult
    {
        $raw = $response->raw();
        if (!is_array($raw)) {
            // print_r string / unknown / UAT shape — no machine-readable status.
            return RefundStatusResult::noSignal();
        }

        $status = $this->extractStatus($raw);
        foreach (self::WRAPPER_KEYS as $key) {
            if ($status === null && isset($raw[$key]) && is_array($raw[$key])) {
                $status = $this->extractStatus($raw[$key]);
            }
        }
        if ($status === null) {
            return RefundStatusResult::noSignal();
        }

        return $this->classify($status);
    }

    /**
     * The first present, STRING status value among the candidate fields. A non-string scalar
     * (e.g. a bare number) is ignored — a numeric status is ambiguous and must not be classified.
     *
     * @param array<mixed> $data
     * @return string|null
     */
    private function extractStatus(array $data): ?string
    {
        foreach (self::STATUS_FIELDS as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                return $data[$field];
            }
        }
        return null;
    }

    /**
     * @param string $raw
     * @return RefundStatusResult
     */
    private function classify(string $raw): RefundStatusResult
    {
        $norm = strtolower(trim($raw));
        if (in_array($norm, self::SUCCESS_VALUES, true)) {
            return RefundStatusResult::success();
        }
        if (in_array($norm, self::FAILURE_VALUES, true)) {
            return RefundStatusResult::failure();
        }
        if (in_array($norm, self::PROCESSING_VALUES, true)) {
            return RefundStatusResult::processing();
        }
        // Recognized field, UNrecognized value — do not guess; wait for the next tick / strand alarm.
        return RefundStatusResult::noSignal();
    }
}
