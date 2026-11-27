<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\SettlementReadbackResult;

/**
 * Reads a child segment's SETTLED amount back from a get_aggregator_transactions response, the
 * INV-18 readback that gates RELEASE_REQUESTED → RELEASED (A5c-5). Pure: a decoded response in,
 * a SettlementReadbackResult out.
 *
 * ── PROD-VERIFY (O-11) — THIS FILE IS THE ONE ISOLATED POINT OF UNCERTAINTY ──
 * PayU UAT has no settlement→release lifecycle (E-07/E-12): get_aggregator_transactions exposes
 * the split ALLOCATION but no SETTLED/RELEASED figure, so in sandbox this reader always returns
 * notFound() and the recon is a safe no-op (a segment never wrongly flips to RELEASED). The
 * exact production response shape — the wrapper key, the per-txn id field, the per-child
 * settlement-amount field — could not be observed in UAT. The traversal below targets the
 * documented shape (E-10: per-txn `is_parent_transaction`, `splitCreated`, nested `splitInfo` +
 * amounts) with a deliberately conservative discriminator: a settlement signal is reported ONLY
 * when an explicit settlement-amount field is present on the matched child entry. The mere
 * allocation amount (present from the moment of split, before any release) is NOT a signal.
 *
 * At O-11, run a real own-account (CHLD01) order → split → release, capture the live
 * get_aggregator_transactions body, and calibrate the four candidate-field lists below to the
 * real field names. No other file changes for that calibration.
 *
 * O-11 must ALSO confirm the settled-amount UNIT: this reader parses it as a 2-decimal RUPEE
 * string via Money::fromRupees. If PayU returns the figure in integer paise, switch that one line
 * to Money::fromPaise((int) $settled) — otherwise a correct settlement is read 100× off and (safe
 * direction) FREEZES rather than over-releasing. The recon also reads only page 1 of the
 * transaction window; at real volume verify the target txn lands on page 1 or add pagination.
 */
class SettlementReadbackReader
{
    /** Keys under which the response may wrap its list of transaction rows. */
    private const TXN_LIST_KEYS = ['transactions', 'transaction_details', 'result', 'data', 'records'];

    /** Per-txn-row fields that may carry the PayU transaction id (mihpayid). */
    private const TXN_ID_FIELDS = ['mihpayid', 'payuId', 'payu_id', 'id', 'transactionId', 'txnid'];

    /** Per-txn-row keys under which the per-child split/settlement detail map lives. */
    private const SPLIT_INFO_KEYS = ['splitInfo', 'split_info', 'splits', 'childTransactions'];

    /**
     * Per-child fields that denote a SETTLED/RELEASED amount (NOT the allocation). The presence
     * of one of these — and only this — is what makes a settlement "signalled". Calibrate at O-11.
     */
    private const SETTLED_AMOUNT_FIELDS = [
        'settledAmount',
        'settled_amount',
        'releasedAmount',
        'released_amount',
        'settlementAmount',
        'merchantNetAmount',
    ];

    /**
     * @param PayuApiResponse $response The get_aggregator_transactions response
     * @param string $mihpayid The transaction whose child settlement we want
     * @param string $childKey The PayU child merchant key of the segment being reconciled
     * @return SettlementReadbackResult notFound() unless an explicit settled amount is present
     */
    public function read(PayuApiResponse $response, string $mihpayid, string $childKey): SettlementReadbackResult
    {
        if ($mihpayid === '' || $childKey === '') {
            return SettlementReadbackResult::notFound();
        }

        $raw = $response->raw();
        if (!is_array($raw)) {
            // print_r string / unknown / UAT shape — no machine-readable settlement figure.
            return SettlementReadbackResult::notFound();
        }

        $row = $this->findTransactionRow($raw, $mihpayid);
        if ($row === null) {
            return SettlementReadbackResult::notFound();
        }

        $childEntry = $this->findChildEntry($row, $childKey);
        if ($childEntry === null) {
            return SettlementReadbackResult::notFound();
        }

        $settled = $this->extractField($childEntry, self::SETTLED_AMOUNT_FIELDS);
        if ($settled === null || !is_numeric($settled)) {
            // Only an allocation amount (or nothing) — not a settlement signal.
            return SettlementReadbackResult::notFound();
        }

        $paise = Money::fromRupees((string) $settled)->paise;
        if ($paise <= 0) {
            // A non-positive settled figure on a segment awaiting release is "not settled yet"
            // — or an O-11 field miscalibration reading a zero/allocation field. Either way it is
            // NOT a real ₹0 release, so report no signal rather than found(0). This keeps a wrong
            // candidate-field guess from mass-FREEZING every positive segment (found(0) would
            // mismatch every expectedNet > 0). The reader therefore only ever signals a positive
            // settled amount.
            return SettlementReadbackResult::notFound();
        }

        return SettlementReadbackResult::found($paise);
    }

    /**
     * Locate the transaction row matching the mihpayid within the (possibly wrapped) response.
     *
     * @param array<mixed> $raw
     * @param string $mihpayid
     * @return array<string, mixed>|null
     */
    private function findTransactionRow(array $raw, string $mihpayid): ?array
    {
        foreach ($this->candidateRowLists($raw) as $list) {
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = $this->extractField($row, self::TXN_ID_FIELDS);
                if ($id !== null && (string) $id === $mihpayid) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * Yield every plausible list-of-rows in the response: the body itself if it is already a
     * list of rows, plus any wrapper-keyed list (transactions/result/…).
     *
     * @param array<mixed> $raw
     * @return array<int, array<mixed>>
     */
    private function candidateRowLists(array $raw): array
    {
        $lists = [];
        if ($this->isListOfRows($raw)) {
            $lists[] = $raw;
        }
        foreach (self::TXN_LIST_KEYS as $key) {
            if (isset($raw[$key]) && is_array($raw[$key]) && $this->isListOfRows($raw[$key])) {
                $lists[] = $raw[$key];
            }
        }
        return $lists;
    }

    /**
     * @param array<mixed> $value
     * @return bool True when the array looks like a sequential list whose items are rows (arrays)
     */
    private function isListOfRows(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find this child's split/settlement entry on a transaction row. The split detail is a map
     * keyed by child merchant key under one of the SPLIT_INFO_KEYS.
     *
     * @param array<mixed> $row
     * @param string $childKey
     * @return array<string, mixed>|null
     */
    private function findChildEntry(array $row, string $childKey): ?array
    {
        foreach (self::SPLIT_INFO_KEYS as $key) {
            if (!isset($row[$key]) || !is_array($row[$key])) {
                continue;
            }
            $entry = $row[$key][$childKey] ?? null;
            if (is_array($entry)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Return the first present, scalar value among the candidate field names.
     *
     * @param array<mixed> $data
     * @param string[] $fields
     * @return scalar|null
     */
    private function extractField(array $data, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                return $data[$field];
            }
        }
        return null;
    }
}
