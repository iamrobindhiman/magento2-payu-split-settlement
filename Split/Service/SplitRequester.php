<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\SplitOrder;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Fires PayU payment_split for one order, exactly once, against the A4 snapshot,
 * and maps every outcome onto the header state machine.
 *
 * Idempotency (matrix INV-5, Fact 1 — one split per mihpayid):
 *   - A DONE header is never touched again.
 *   - A REQUESTED header (a prior run wrote-ahead, then maybe crashed) checks
 *     get_split_info FIRST; if the split already exists it goes straight to DONE
 *     with no POST.
 *   - AGG-104 "already split" on the POST is success, not an error — the belt to
 *     the get_split_info braces.
 *
 * Safety:
 *   - The base is rebuilt from the frozen snapshot (SnapshotPlanReader), never live
 *     Webkul (INV-1/INV-8).
 *   - Write-ahead: REQUESTED + timestamp persist before the POST, so a crash is
 *     recoverable.
 *   - No mihpayid yet → leave the header where it is with a reason; a later run
 *     retries once PayU has recorded it.
 *   - PENDING / AGG-110 → stay for retry; only a genuine business failure → FAILED.
 *   - Transport failures propagate (the scan isolates them per-order).
 */
class SplitRequester
{
    /**
     * PayU error codes that mean the split request is PERMANENTLY rejected — the
     * payload or transaction is structurally wrong and an identical retry cannot
     * succeed (KB split-api error table): AGG-103 not-aggregator/not-splittable,
     * AGG-107 invalid payload, AGG-108 amounts don't sum to the txn. Every OTHER
     * failure (AGG-300 hash, AGG-101/102 account/child config, AGG-301 bad payuId,
     * unknown/ambiguous body) is fixable out-of-band, so it is kept transient and
     * escalated by the aged-order alarm rather than silently stranding the money.
     */
    private const TERMINAL_FAILURE_CODES = ['AGG-103', 'AGG-107', 'AGG-108'];

    /**
     * @param PayuSplitClient $gateway
     * @param SnapshotPlanReader $planReader
     * @param SplitStateMachine $stateMachine
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuSplitClient $gateway,
        private readonly SnapshotPlanReader $planReader,
        private readonly SplitStateMachine $stateMachine,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param SplitOrder $header
     * @return void
     */
    public function request(SplitOrder $header): void
    {
        if ($header->getSplitState() === SplitOrder::STATE_DONE) {
            return; // INV-5 — a completed split is immutable
        }

        $mihpayid = $header->getMihpayid();
        if ($mihpayid === null) {
            $this->stateMachine->recordTransient(
                $header,
                'cannot split yet — PayU mihpayid not available on the order'
            );
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: order %d is trigger-ready but has no mihpayid — split deferred.',
                $header->getOrderId()
            ));
            return;
        }

        // A corruption in the snapshot (segments no longer sum to the txn) is a genuine
        // terminal error — let it propagate to the scan, which marks the header FAILED.
        $plan = $this->planReader->read($header);

        // Recovery: a REQUESTED header may already carry a split at PayU. Verify
        // before re-POSTing so a crash-retry never creates a second split.
        if ($header->getSplitState() === SplitOrder::STATE_REQUESTED) {
            try {
                $existing = $this->gateway->getSplitInfo($mihpayid);
            } catch (PayuSplitRequestException $e) {
                $this->keepForRetry($header, $e);
                return;
            }
            if ($this->readbackShowsSplit($existing)) {
                $this->stateMachine->markDone($header, $this->safeSerialize($existing));
                return;
            }
            if ($this->readbackPending($existing)) {
                // KB §5g.5: on a PENDING split (saved, not yet created), poll — never
                // re-POST payment_split.
                $this->stateMachine->recordTransient($header, 'split pending at PayU — poll next run');
                return;
            }
        }

        // Fresh path: write-ahead the intent, then POST.
        $this->stateMachine->markRequested($header);

        try {
            $response = $this->gateway->paymentSplit($plan, $mihpayid);
        } catch (PayuSplitRequestException $e) {
            // Transport failure (network / PayU 5xx) is retryable, NOT terminal — and
            // the POST may have landed at PayU even though the response was lost. Leave
            // the header REQUESTED so the next scan's get_split_info recovery reconciles.
            // Only a genuine business outcome (below) ever moves the header to FAILED.
            $this->keepForRetry($header, $e);
            return;
        }

        if ($response->isSuccess() || $response->isAlreadySplit()) {
            $this->stateMachine->markDone($header, $this->safeReadback($mihpayid));
            return;
        }

        if ($response->isPending()) {
            $this->stateMachine->recordTransient($header, 'split pending at PayU — poll next run');
            return;
        }

        if ($response->isTransientLock()) {
            $this->stateMachine->recordTransient($header, 'transient lock (AGG-110) — retry next run');
            return;
        }

        // Only a structurally permanent error terminalises the header — an identical
        // retry cannot fix a malformed payload or a non-aggregator transaction.
        if ($response->isFailure() && in_array($response->errorCode(), self::TERMINAL_FAILURE_CODES, true)) {
            $this->stateMachine->markFailed($header, $this->describeFailure($response));
            return;
        }

        // A fixable config error, or an ambiguous/status-less body, is kept transient
        // (the POST may even have landed) rather than silently stranding the money. The
        // aged-order compliance alarm escalates it.
        $this->stateMachine->recordTransient(
            $header,
            'split not completed (will retry): ' . $this->describeFailure($response)
        );
        $this->logger->warning(sprintf(
            'RKD_PayuSplit: order %d split returned a non-terminal failure — %s',
            $header->getOrderId(),
            $this->describeFailure($response)
        ));
    }

    /**
     * Record a transient transport failure and leave the header where it is (REQUESTED)
     * for the next scan to retry via the get_split_info recovery path.
     *
     * @param SplitOrder $header
     * @param PayuSplitRequestException $e
     * @return void
     */
    private function keepForRetry(SplitOrder $header, PayuSplitRequestException $e): void
    {
        $this->stateMachine->recordTransient(
            $header,
            'transport error contacting PayU — retry next run: ' . $e->getMessage()
        );
        $this->logger->warning(sprintf(
            'RKD_PayuSplit: transport error splitting order %d — %s',
            $header->getOrderId(),
            $e->getMessage()
        ));
    }

    /**
     * Whether a get_split_info readback confirms the split already exists. PayU reports
     * splitStatus 'success' on a created split (proven live, E-/A5a smoke). Checking the
     * actual status field — not a substring of the serialised body — avoids a false
     * positive (a body that merely echoes 'splitInfo'/'aggregatorSubAmt' marking a header
     * DONE without a real split, leaving vendors unpaid under the INV-5 immutability).
     * A false negative is harmless: the caller falls through to the POST where AGG-104
     * is the idempotency backstop.
     *
     * @param PayuApiResponse $response
     * @return bool
     */
    private function readbackShowsSplit(PayuApiResponse $response): bool
    {
        return $response->isSuccess() && $response->field('splitStatus') === 'success';
    }

    /**
     * Whether a get_split_info readback reports the split is still PENDING at PayU
     * (saved, not yet created). The caller must poll on the next run, never re-POST.
     *
     * @param PayuApiResponse $response
     * @return bool
     */
    private function readbackPending(PayuApiResponse $response): bool
    {
        return $response->isPending()
            || strtolower((string) ($response->field('splitStatus') ?? '')) === 'pending';
    }

    /**
     * Serialise a response for audit storage, tolerating an unserialisable raw body
     * (e.g. invalid UTF-8). A serialise failure must NEVER propagate and flip a
     * confirmed, existing split to FAILED — it degrades to a null stored readback.
     *
     * @param PayuApiResponse $response
     * @return string|null
     */
    private function safeSerialize(PayuApiResponse $response): ?string
    {
        try {
            return $this->serialize($response);
        } catch (\Throwable $t) {
            $this->logger->warning('RKD_PayuSplit: split readback serialise failed — ' . $t->getMessage());
            return null;
        }
    }

    /**
     * Best-effort audit readback after a successful split. A transport failure here
     * must not undo the split, so it degrades to a null readback.
     *
     * @param string $mihpayid
     * @return string|null
     */
    private function safeReadback(string $mihpayid): ?string
    {
        try {
            return $this->serialize($this->gateway->getSplitInfo($mihpayid));
        } catch (\Throwable $t) {
            $this->logger->warning(
                'RKD_PayuSplit: split succeeded but get_split_info readback failed — ' . $t->getMessage()
            );
            return null;
        }
    }

    /**
     * @param PayuApiResponse $response
     * @return string
     */
    private function serialize(PayuApiResponse $response): string
    {
        $raw = $response->raw();
        $rawString = is_string($raw) ? $raw : $this->json->serialize($raw);
        return $this->json->serialize($response->fields()) . ' ' . $rawString;
    }

    /**
     * @param PayuApiResponse $response
     * @return string
     */
    private function describeFailure(PayuApiResponse $response): string
    {
        $code = $response->errorCode() ?? 'no-code';
        return sprintf('payment_split failed [%s]: %s', $code, $response->message());
    }
}
