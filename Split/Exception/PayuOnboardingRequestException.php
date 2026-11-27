<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * Raised when a PayU onboarding request cannot be completed at the transport level —
 * connection refused, timeout, DNS failure, or a 5xx from PayU's own servers.
 *
 * Deliberately distinct from a PayU business refusal. A 422 "Bank details already
 * submitted" is an ANSWER, not a failure, and comes back as an OnboardingApiResponse
 * so the caller can act on it. This exception means "we never got an answer" — the
 * onboarding cron leaves the vendor's state untouched and retries on the next tick
 * (N-3: every operation idempotent and safe to re-run).
 */
class PayuOnboardingRequestException extends RuntimeException
{
}
