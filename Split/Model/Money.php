<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

use InvalidArgumentException;

/**
 * Integer-paise money value object. All split arithmetic happens in paise to
 * avoid floating-point currency errors; rupee strings are parsed on the way in
 * and formatted to two decimals on the way out (PayU requires 2-decimal amounts).
 */
class Money
{
    /**
     * @param int $paise Amount in paise (1 rupee = 100 paise). May be negative for intermediate results.
     */
    private function __construct(public readonly int $paise)
    {
    }

    /**
     * Parse a rupee amount (Webkul stores decimal(12,4)) to the nearest paise.
     *
     * @param string|int|float $rupees
     * @return self
     * @throws InvalidArgumentException When the value is not a plain numeric amount.
     */
    public static function fromRupees(string|int|float $rupees): self
    {
        if (!is_numeric($rupees)) {
            throw new InvalidArgumentException(
                sprintf('Non-numeric money value: "%s".', (string) $rupees)
            );
        }
        return new self((int) round((float) $rupees * 100));
    }

    /**
     * @param int $paise
     * @return self
     */
    public static function fromPaise(int $paise): self
    {
        return new self($paise);
    }

    /**
     * @return self
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * @param self $other
     * @return self
     */
    public function add(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    /**
     * @param self $other
     * @return self
     */
    public function subtract(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    /**
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    /**
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    /**
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->paise === $other->paise;
    }

    /**
     * Two-decimal rupee string for the PayU payload (e.g. 67932 paise => "679.32").
     * Formatted from the integer paise directly so no float ever touches the output.
     *
     * @return string
     */
    public function toRupeeString(): string
    {
        $sign = $this->paise < 0 ? '-' : '';
        $abs = abs($this->paise);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
