<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * Normalised view of a PayU postservice response. PayU replies in two shapes —
 * JSON (payment_split, refunds, release_settlement) and PHP print_r-in-<pre>
 * (get_split_info on UAT). Both are flattened to top-level scalar fields here so
 * control-flow code reads a single interface. Construct via PayuResponseParser.
 */
class PayuApiResponse
{
    /**
     * @param array<string, string> $fields Top-level scalar fields (status, error_code, message, …)
     * @param string $format json | print_r | unknown
     * @param mixed $raw Decoded array or raw body, retained for nested inspection
     */
    private function __construct(
        private readonly array $fields,
        private readonly string $format,
        private readonly mixed $raw
    ) {
    }

    /**
     * @param array<string, string> $fields
     * @param string $format
     * @param mixed $raw
     * @return self
     */
    public static function fromFields(array $fields, string $format, mixed $raw): self
    {
        return new self($fields, $format, $raw);
    }

    /**
     * status 1 = success for postservice commands.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return ($this->fields['status'] ?? null) === '1';
    }

    /**
     * status 2 = PENDING (split saved, not yet created). Poll get_split_info; do NOT re-post.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return ($this->fields['status'] ?? null) === '2';
    }

    /**
     * status 0 = failure.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return ($this->fields['status'] ?? null) === '0';
    }

    /**
     * @return string|null
     */
    public function status(): ?string
    {
        return $this->fields['status'] ?? null;
    }

    /**
     * @return string|null AGG-XXX code when present
     */
    public function errorCode(): ?string
    {
        return $this->fields['error_code'] ?? null;
    }

    /**
     * AGG-104 ("split already exists") is the idempotency signal — the original split
     * succeeded; treat the order as already split rather than retrying.
     *
     * @return bool
     */
    public function isAlreadySplit(): bool
    {
        return $this->errorCode() === 'AGG-104';
    }

    /**
     * AGG-110 ("transaction locked; retry later") is a transient lock — safe to back off
     * and retry the same call.
     *
     * @return bool
     */
    public function isTransientLock(): bool
    {
        return $this->errorCode() === 'AGG-110';
    }

    /**
     * release_settlement idempotency: PayU replies "Release request is already accepted"
     * (no AGG code) when the release was already triggered. The caller should treat this
     * as done — not as a failure to retry. (KB §7c.)
     *
     * @return bool
     */
    public function isAlreadyReleased(): bool
    {
        return str_contains($this->message(), 'already accepted');
    }

    /**
     * Human-readable message from whichever field PayU populated for this command.
     *
     * @return string
     */
    public function message(): string
    {
        return (string)(
            $this->fields['error_desc']
            ?? $this->fields['error_message']
            ?? $this->fields['message']
            ?? $this->fields['msg']
            ?? ''
        );
    }

    /**
     * @param string $name
     * @return string|null
     */
    public function field(string $name): ?string
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return string
     */
    public function format(): string
    {
        return $this->format;
    }

    /**
     * @return mixed
     */
    public function raw(): mixed
    {
        return $this->raw;
    }
}
