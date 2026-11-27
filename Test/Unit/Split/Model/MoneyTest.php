<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\Money;
use PHPUnit\Framework\TestCase;

/**
 * Money is the integer-paise value object underpinning every split amount.
 * Currency must never be added/subtracted as a float — all arithmetic happens
 * in paise (int); rupee strings are only parsed in and formatted out.
 */
class MoneyTest extends TestCase
{
    public function testFromRupeesParsesWholeAndDecimal(): void
    {
        $this->assertSame(90000, Money::fromRupees('900.0000')->paise);
        $this->assertSame(71928, Money::fromRupees('719.28')->paise);
        $this->assertSame(151840, Money::fromRupees('1518.40')->paise);
        $this->assertSame(0, Money::fromRupees('0')->paise);
        $this->assertSame(5, Money::fromRupees('0.05')->paise);
    }

    public function testFromRupeesRoundsFourDecimalToPaise(): void
    {
        // Webkul stores decimal(12,4); PayU wants 2 decimals → round to nearest paise.
        $this->assertSame(90013, Money::fromRupees('900.1250')->paise);
        $this->assertSame(90012, Money::fromRupees('900.1240')->paise);
    }

    public function testToRupeeStringAlwaysTwoDecimals(): void
    {
        $this->assertSame('900.00', Money::fromPaise(90000)->toRupeeString());
        $this->assertSame('719.28', Money::fromPaise(71928)->toRupeeString());
        $this->assertSame('0.05', Money::fromPaise(5)->toRupeeString());
        $this->assertSame('0.00', Money::zero()->toRupeeString());
    }

    public function testAddAndSubtract(): void
    {
        $a = Money::fromPaise(90000);
        $b = Money::fromPaise(71928);
        $this->assertSame(161928, $a->add($b)->paise);
        $this->assertSame(18072, $a->subtract($b)->paise);
    }

    public function testIsNegative(): void
    {
        $this->assertTrue(Money::fromPaise(100)->subtract(Money::fromPaise(150))->isNegative());
        $this->assertFalse(Money::fromPaise(150)->subtract(Money::fromPaise(150))->isNegative());
    }

    public function testEquals(): void
    {
        $this->assertTrue(Money::fromPaise(500)->equals(Money::fromRupees('5.00')));
        $this->assertFalse(Money::fromPaise(500)->equals(Money::fromPaise(501)));
    }

    public function testFromRupeesThrowsOnNonNumericString(): void
    {
        // Guard against comma-grouped / malformed strings from any non-DB source silently
        // casting to a wrong float (e.g. "1,200.00" -> 1.0).
        $this->expectException(\InvalidArgumentException::class);
        Money::fromRupees('1,200.00');
    }

    public function testIsPositive(): void
    {
        $this->assertTrue(Money::fromPaise(1)->isPositive());
        $this->assertFalse(Money::zero()->isPositive());
        $this->assertFalse(Money::fromPaise(-1)->isPositive());
    }
}
