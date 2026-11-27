<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RKD\PayuSplit\Split\Model\Submerchant;
use RuntimeException;

/**
 * An onboarding step that must stop the vendor at FAILED rather than be retried: PayU
 * refused the data, the credentials are wrong, or PayU handed back an account outside our
 * aggregator. Carries which of those it was, as a Submerchant::ERROR_* value.
 *
 * The message is stored in last_error and shown to staff, so it must never carry a value
 * — only field names and PayU's own wording.
 */
class OnboardingStepFailedException extends RuntimeException
{
    /**
     * @param string $errorClass One of Submerchant::ERROR_*
     * @param string $message
     */
    public function __construct(
        private readonly string $errorClass,
        string $message
    ) {
        parent::__construct($message);
    }

    /**
     * @return string
     */
    public function getErrorClass(): string
    {
        return $this->errorClass;
    }

    /**
     * @param string $message
     * @return self
     */
    public static function business(string $message): self
    {
        return new self(Submerchant::ERROR_BUSINESS, $message);
    }

    /**
     * @param string $message
     * @return self
     */
    public static function configuration(string $message): self
    {
        return new self(Submerchant::ERROR_CONFIGURATION, $message);
    }

    /**
     * @param string $message
     * @return self
     */
    public static function orphan(string $message): self
    {
        return new self(Submerchant::ERROR_ORPHAN, $message);
    }
}
