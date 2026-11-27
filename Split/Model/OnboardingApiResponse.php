<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * A normalised PayU onboarding response.
 *
 * The onboarding API is REST/JSON, unlike the transaction postservice the split
 * engine talks to, so success is the HTTP status and errors arrive in one of three
 * shapes we have actually observed on UAT:
 *
 *   422  {"errors":{"bank_details":["Bank details already submitted"]}}
 *   422  {"error":"param is missing or the value is empty: product_account"}
 *   401  {"status":"Unauthorized"}
 *
 * errorMessages() flattens all three, so a caller never has to know which one it got.
 */
class OnboardingApiResponse
{
    private const HTTP_OK_MIN = 200;
    private const HTTP_OK_MAX = 299;
    private const HTTP_UNAUTHORIZED = 401;

    /**
     * @param int $httpStatus
     * @param array<string, mixed> $body Decoded JSON; empty when the body was not JSON
     * @param string $raw The untouched response body, for diagnostics
     */
    public function __construct(
        private readonly int $httpStatus,
        private readonly array $body,
        private readonly string $raw
    ) {
    }

    /**
     * @return int
     */
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->httpStatus >= self::HTTP_OK_MIN && $this->httpStatus <= self::HTTP_OK_MAX;
    }

    /**
     * A token that expired mid-run looks exactly like this. The client refreshes and
     * retries once on it rather than surfacing it (T-10).
     *
     * @return bool
     */
    public function isUnauthorized(): bool
    {
        return $this->httpStatus === self::HTTP_UNAUTHORIZED;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return string
     */
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * The merchant record PayU echoes back on create, update and read alike.
     *
     * @return array<string, mixed>|null
     */
    public function productAccount(): ?array
    {
        $account = $this->body['product_account'] ?? null;
        return is_array($account) ? $account : null;
    }

    /**
     * Every error string in the response, flattened across the three observed shapes.
     *
     * @return array<int, string>
     */
    public function errorMessages(): array
    {
        $messages = array_merge($this->nestedErrors(), $this->scalarErrors());

        // {"status":"Unauthorized"} carries its only diagnostic in "status", but the
        // same key holds "Active" on a successful read — mine it only on a failure.
        if ($messages === [] && !$this->isSuccess()) {
            $status = $this->scalarAt('status');
            if ($status !== null) {
                $messages[] = $status;
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * The {"errors":{"field":["message"]}} shape, at any nesting depth.
     *
     * @return array<int, string>
     */
    private function nestedErrors(): array
    {
        $errors = $this->body['errors'] ?? null;
        if (!is_array($errors)) {
            return [];
        }

        $messages = [];
        array_walk_recursive($errors, static function ($message) use (&$messages): void {
            if (is_scalar($message)) {
                $messages[] = (string)$message;
            }
        });

        return $messages;
    }

    /**
     * The {"error":"..."} and {"message":"..."} shapes.
     *
     * @return array<int, string>
     */
    private function scalarErrors(): array
    {
        $messages = [];
        foreach (['error', 'message'] as $key) {
            $value = $this->scalarAt($key);
            if ($value !== null) {
                $messages[] = $value;
            }
        }
        return $messages;
    }

    /**
     * @param string $key
     * @return string|null
     */
    private function scalarAt(string $key): ?string
    {
        $value = $this->body[$key] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * A single line safe to store in rkd_payu_submerchant.last_error and show to staff.
     *
     * @return string
     */
    public function errorSummary(): string
    {
        $messages = $this->errorMessages();
        if ($messages === []) {
            return sprintf('PayU returned HTTP %d with no error detail.', $this->httpStatus);
        }
        return sprintf('HTTP %d: %s', $this->httpStatus, implode('; ', $messages));
    }
}
