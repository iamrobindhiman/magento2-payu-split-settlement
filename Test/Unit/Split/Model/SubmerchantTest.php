<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model;

use RKD\PayuSplit\Split\Model\Submerchant;
use PHPUnit\Framework\TestCase;

/**
 * The onboarding state machine. These guards exist because a row is created at vendor
 * approval — before PayU has been called — so "a row exists" and "the vendor can be
 * paid" are very different things, and the code must never confuse them.
 */
class SubmerchantTest extends TestCase
{
    private function submerchant(array $data = []): Submerchant
    {
        $model = $this->getMockBuilder(Submerchant::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }
        return $model;
    }

    public function testStateDefaultsToNotStartedWhenUnset(): void
    {
        $this->assertSame(Submerchant::STATE_NOT_STARTED, $this->submerchant()->getOnboardingState());
        $this->assertSame(
            Submerchant::STATE_NOT_STARTED,
            $this->submerchant(['onboarding_state' => ''])->getOnboardingState()
        );
    }

    public function testAQueuedRowHasNoMerchantKeyYet(): void
    {
        $queued = $this->submerchant(['onboarding_state' => Submerchant::STATE_QUEUED]);
        $this->assertFalse($queued->hasMerchantKey());
        $this->assertSame('', $queued->getMerchantKey());
    }

    public function testPayuUuidIsNullUntilPayuHasCreatedTheAccount(): void
    {
        $this->assertNull($this->submerchant()->getPayuUuid());
        $this->assertNull($this->submerchant(['payu_uuid' => ''])->getPayuUuid());
        $this->assertSame('0000-abc', $this->submerchant(['payu_uuid' => '0000-abc'])->getPayuUuid());
    }

    /**
     * @dataProvider forwardPathProvider
     */
    public function testForwardTransitionsAreAllowed(string $from, string $to): void
    {
        $this->assertTrue($this->submerchant(['onboarding_state' => $from])->canTransitionTo($to));
    }

    /** @return array<string, string[]> */
    public static function forwardPathProvider(): array
    {
        return [
            'approval queues it' => [Submerchant::STATE_NOT_STARTED, Submerchant::STATE_QUEUED],
            'created at payu' => [Submerchant::STATE_QUEUED, Submerchant::STATE_CREATED],
            'bank attached' => [Submerchant::STATE_CREATED, Submerchant::STATE_BANK_ATTACHED],
            'into the esign queue' => [Submerchant::STATE_BANK_ATTACHED, Submerchant::STATE_AWAITING_ESIGN],
            'payu activates' => [Submerchant::STATE_AWAITING_ESIGN, Submerchant::STATE_ACTIVE],
            'retry after failure' => [Submerchant::STATE_FAILED, Submerchant::STATE_QUEUED],
        ];
    }

    /**
     * @dataProvider illegalTransitionProvider
     */
    public function testIllegalTransitionsAreRefused(string $from, string $to): void
    {
        $this->assertFalse($this->submerchant(['onboarding_state' => $from])->canTransitionTo($to));
    }

    /** @return array<string, string[]> */
    public static function illegalTransitionProvider(): array
    {
        return [
            'cannot skip creation' => [Submerchant::STATE_QUEUED, Submerchant::STATE_ACTIVE],
            'cannot skip the bank step' => [Submerchant::STATE_CREATED, Submerchant::STATE_AWAITING_ESIGN],
            'cannot skip the e-sign' => [Submerchant::STATE_BANK_ATTACHED, Submerchant::STATE_ACTIVE],
            'active is terminal' => [Submerchant::STATE_ACTIVE, Submerchant::STATE_QUEUED],
            'no going backwards' => [Submerchant::STATE_BANK_ATTACHED, Submerchant::STATE_CREATED],
            'unknown state' => [Submerchant::STATE_QUEUED, 'MADE_UP'],
        ];
    }

    public function testAnyLiveStateCanFail(): void
    {
        foreach ([
            Submerchant::STATE_QUEUED,
            Submerchant::STATE_CREATED,
            Submerchant::STATE_BANK_ATTACHED,
            Submerchant::STATE_AWAITING_ESIGN,
        ] as $state) {
            $this->assertTrue(
                $this->submerchant(['onboarding_state' => $state])->canTransitionTo(Submerchant::STATE_FAILED),
                sprintf('%s should be able to fail', $state)
            );
        }
    }

    public function testFailedDoesNotReEnterFailed(): void
    {
        $failed = $this->submerchant(['onboarding_state' => Submerchant::STATE_FAILED]);
        $this->assertFalse($failed->canTransitionTo(Submerchant::STATE_FAILED));
    }

    public function testEsignQueueFlags(): void
    {
        $waiting = $this->submerchant(['onboarding_state' => Submerchant::STATE_AWAITING_ESIGN]);
        $this->assertTrue($waiting->isAwaitingEsign());
        $this->assertFalse($waiting->isEsignSent());

        $waiting->setData('esign_sent_at', '2026-09-10 11:00:00');
        $this->assertTrue($waiting->isEsignSent());
    }

    public function testLastErrorNormalisesEmptyToNull(): void
    {
        $this->assertNull($this->submerchant()->getLastError());
        $this->assertNull($this->submerchant(['last_error' => ''])->getLastError());
        $this->assertSame('boom', $this->submerchant(['last_error' => 'boom'])->getLastError());
    }
}
