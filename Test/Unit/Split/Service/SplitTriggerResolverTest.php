<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\TriggerResult;
use RKD\PayuSplit\Split\Service\SplitTriggerResolver;
use PHPUnit\Framework\TestCase;

/**
 * The pure split-trigger decision (matrix §1.2 / §5). Given the order's vendor
 * seller-ids, each one's shipped-or-cancelled (terminal) flag, whether a money
 * event is pending, the paid-at and current timestamps, and the configured
 * ceiling/alert day thresholds, the resolver returns the earliest-firing trigger
 * (ALL_TERMINAL → MONEY_EVENT → CEILING) or null, plus whether a compliance alert
 * is due. No DB, no clock — every input is passed in, so each condition is
 * asserted in isolation.
 */
class SplitTriggerResolverTest extends TestCase
{
    private SplitTriggerResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SplitTriggerResolver();
    }

    private function resolve(
        array $vendorSellerIds,
        array $terminalFlags,
        bool $moneyEvent = false,
        ?string $paidAt = '2026-06-01 00:00:00',
        string $now = '2026-06-02 00:00:00',
        int $ceilingDays = 25,
        int $alertDays = 23
    ): TriggerResult {
        return $this->resolver->resolve(
            $vendorSellerIds,
            $terminalFlags,
            $moneyEvent,
            $paidAt,
            $now,
            $ceilingDays,
            $alertDays
        );
    }

    public function testAllVendorsShippedTriggersAllTerminal(): void
    {
        $result = $this->resolve([18, 20], [18 => true, 20 => true]);
        $this->assertSame(SplitTriggerResolver::TRIGGER_ALL_TERMINAL, $result->trigger);
        $this->assertFalse($result->alertDue);
    }

    public function testAllVendorsTerminalViaCancellationAlsoTriggers(): void
    {
        // The terminal flag already encodes shipped-OR-cancelled; both read as true.
        $result = $this->resolve([18, 20], [18 => true, 20 => true]);
        $this->assertSame(SplitTriggerResolver::TRIGGER_ALL_TERMINAL, $result->trigger);
    }

    public function testOnePendingSubOrderSuppressesTrigger(): void
    {
        $result = $this->resolve([18, 20], [18 => true, 20 => false]);
        $this->assertNull($result->trigger);
        $this->assertFalse($result->alertDue);
    }

    public function testVendorMissingFromTerminalFlagsIsTreatedAsNotTerminal(): void
    {
        // seller 20 has no marketplace_orders row at all → not terminal.
        $result = $this->resolve([18, 20], [18 => true]);
        $this->assertNull($result->trigger);
    }

    public function testMoneyEventTriggersWhenNotAllTerminal(): void
    {
        $result = $this->resolve([18, 20], [18 => true, 20 => false], true);
        $this->assertSame(SplitTriggerResolver::TRIGGER_MONEY_EVENT, $result->trigger);
    }

    public function testAllTerminalTakesPrecedenceOverMoneyEvent(): void
    {
        $result = $this->resolve([18, 20], [18 => true, 20 => true], true);
        $this->assertSame(SplitTriggerResolver::TRIGGER_ALL_TERMINAL, $result->trigger);
    }

    public function testDay23DoesNotTriggerCeilingButRaisesAlert(): void
    {
        $result = $this->resolve(
            [18, 20],
            [18 => true, 20 => false],
            false,
            '2026-06-01 00:00:00',
            '2026-06-24 00:00:00' // 23 days later
        );
        $this->assertNull($result->trigger);
        $this->assertTrue($result->alertDue);
    }

    public function testDay24IsStillBelowCeilingButAlerting(): void
    {
        $result = $this->resolve(
            [18, 20],
            [18 => true, 20 => false],
            false,
            '2026-06-01 00:00:00',
            '2026-06-25 00:00:00' // 24 days later
        );
        $this->assertNull($result->trigger);
        $this->assertTrue($result->alertDue);
    }

    public function testDay25TriggersCeiling(): void
    {
        $result = $this->resolve(
            [18, 20],
            [18 => true, 20 => false],
            false,
            '2026-06-01 00:00:00',
            '2026-06-26 00:00:00' // 25 days later
        );
        $this->assertSame(SplitTriggerResolver::TRIGGER_CEILING, $result->trigger);
        // alertDue reflects age alone (>= alertDays), independent of the trigger — the
        // scan uses it to alarm any aged order that does not actually reach a split.
        $this->assertTrue($result->alertDue);
    }

    public function testAlertDueReflectsAgeEvenWhenAterminalTriggerFires(): void
    {
        // An aged order that is ALL_TERMINAL still reports alertDue=true, so the scan
        // can alarm if the split then fails to complete (no mihpayid / transient loop).
        $result = $this->resolve(
            [18],
            [18 => true],
            false,
            '2026-06-01 00:00:00',
            '2026-06-25 00:00:00' // 24 days — aged
        );
        $this->assertSame(SplitTriggerResolver::TRIGGER_ALL_TERMINAL, $result->trigger);
        $this->assertTrue($result->alertDue);
    }

    public function testMalformedPaidAtIsTreatedAsAgeZeroNotAThrow(): void
    {
        // A malformed paid_at must not blow up the scan (which would terminalise the
        // header) — it degrades to age 0 (no ceiling, no alert) per the documented
        // contract.
        $result = $this->resolve(
            [18],
            [18 => false],
            false,
            'not-a-real-datetime',
            '2027-01-01 00:00:00',
            25,
            23
        );
        $this->assertNull($result->trigger);
        $this->assertFalse($result->alertDue);
    }

    public function testYoungOrderNoTriggerNoAlert(): void
    {
        $result = $this->resolve([18], [18 => false]);
        $this->assertNull($result->trigger);
        $this->assertFalse($result->alertDue);
    }

    public function testEmptyVendorSetNeverAllTerminal(): void
    {
        // A commission-only order (no vendor segments) must not read as ALL_TERMINAL.
        $result = $this->resolve([], []);
        $this->assertNull($result->trigger);
    }

    public function testNullPaidAtNeverReachesCeiling(): void
    {
        $result = $this->resolve(
            [18],
            [18 => false],
            false,
            null,
            '2027-01-01 00:00:00', // far future, but paid_at unknown
            25,
            23
        );
        $this->assertNull($result->trigger);
        $this->assertFalse($result->alertDue);
    }
}
