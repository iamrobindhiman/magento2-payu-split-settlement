<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant;
use PHPUnit\Framework\TestCase;

/**
 * Which stored rows the resource refuses to delete. Deleting any of these and re-approving the
 * vendor could create a second PayU account (wave-2 N-1). The refusal itself is driven live.
 */
class SubmerchantDeleteGuardTest extends TestCase
{
    /**
     * @dataProvider rows
     * @param array<string, mixed> $stored
     */
    public function testWhichRowsCannotBeDeleted(array $stored, bool $owned): void
    {
        self::assertSame($owned, Submerchant::isProtectedFromDelete($stored));
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function rows(): array
    {
        return [
            'empty never-started row' => [['onboarding_state' => 'NOT_STARTED', 'payu_merchant_key' => null], false],
            'pre-onboarding row, state column empty' => [['onboarding_state' => null, 'payu_merchant_key' => ''], false],
            'hand-typed legacy mapping (key)' => [['onboarding_state' => 'NOT_STARTED', 'payu_merchant_key' => 'CHLD01'], true],
            'hand-typed legacy mapping (MID only)' => [['onboarding_state' => 'NOT_STARTED', 'payu_mid' => '9000001'], true],
            'queued, never sent' => [['onboarding_state' => 'QUEUED', 'payu_uuid' => null, 'payu_email' => null], true],
            'create sent, answer lost' => [['onboarding_state' => 'QUEUED', 'payu_uuid' => null, 'payu_email' => 'v@x.in'], true],
            'child exists' => [['onboarding_state' => 'CREATED', 'payu_uuid' => '0000-0000', 'payu_email' => 'v@x.in'], true],
            'failed orphan' => [['onboarding_state' => 'FAILED', 'payu_uuid' => '0000-0000', 'payu_email' => null], true],
            'linked by hand' => [['onboarding_state' => 'NOT_STARTED', 'payu_uuid' => '0000-0000', 'payu_email' => null], true],
        ];
    }
}
