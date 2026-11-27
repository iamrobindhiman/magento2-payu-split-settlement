<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

/**
 * PayU refused to issue a token: the client id, secret or scope is wrong.
 *
 * A subclass of the retryable request exception so that any caller which only knows the parent
 * still stops safely, but OnboardingProcessor catches it FIRST and pauses the whole scan: every
 * vendor would fail the same way, and retrying each one into FAILED only buries the real cause.
 */
class PayuOnboardingCredentialsException extends PayuOnboardingNotSentException
{
}
