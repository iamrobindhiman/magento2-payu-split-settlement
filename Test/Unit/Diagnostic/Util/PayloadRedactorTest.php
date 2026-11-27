<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Diagnostic\Util;

use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Centralised redaction rules. Three trust tiers:
 *  - NEVER log: salt, merchant key (replaced with sentinel ***present*** / ***absent***)
 *  - PARTIAL log: hash (first 8 chars + length only — enough to compare without leaking)
 *  - FULL log: txnid, mihpayid, status, mode, bank_ref_num, error_Message, udfN
 *  - FULL log gated by env: firstname, email, phone (logged on local/staging, redacted on prod)
 *
 * The `prodMode` toggle is what the per-environment redaction switch flips.
 */
class PayloadRedactorTest extends TestCase
{
    public function testReplacesSaltAndKeyWithPresentSentinelWhenSet(): void
    {
        $redactor = new PayloadRedactor(false);
        $payload = ['key' => 'abc123', 'salt' => 'xyz789', 'txnid' => 'T1'];

        $out = $redactor->redact($payload);

        $this->assertSame('***present***', $out['key']);
        $this->assertSame('***present***', $out['salt']);
        $this->assertSame('T1', $out['txnid']);
    }

    public function testReplacesSaltAndKeyWithAbsentSentinelWhenMissing(): void
    {
        $redactor = new PayloadRedactor(false);
        $payload = ['txnid' => 'T1'];

        $out = $redactor->redactSecrets(['key' => null, 'salt' => null]);

        $this->assertSame('***absent***', $out['key']);
        $this->assertSame('***absent***', $out['salt']);
    }

    public function testHashIsPartialFirstEightCharsPlusLength(): void
    {
        $redactor = new PayloadRedactor(false);
        $hash = 'abcdef1234567890fedcba0987654321';

        $masked = $redactor->maskHash($hash);

        $this->assertSame('abcdef12...len=' . strlen($hash), $masked);
    }

    public function testEmptyHashIsMaskedAsAbsent(): void
    {
        $redactor = new PayloadRedactor(false);

        $this->assertSame('***absent***', $redactor->maskHash(''));
        $this->assertSame('***absent***', $redactor->maskHash(null));
    }

    public function testFullModeLogsCustomerPiiInLocalAndStaging(): void
    {
        $redactor = new PayloadRedactor(false); // prodMode = false → full PII
        $payload = [
            'firstname' => 'Robin',
            'email' => 'robin@example.com',
            'phone' => '9999999999',
            'txnid' => 'T1',
        ];

        $out = $redactor->redact($payload);

        $this->assertSame('Robin', $out['firstname']);
        $this->assertSame('robin@example.com', $out['email']);
        $this->assertSame('9999999999', $out['phone']);
    }

    public function testProdModeMasksCustomerPii(): void
    {
        $redactor = new PayloadRedactor(true); // prodMode = true → mask PII
        $payload = [
            'firstname' => 'Robin',
            'email' => 'robin@example.com',
            'phone' => '9999999999',
            'txnid' => 'T1',
        ];

        $out = $redactor->redact($payload);

        $this->assertSame('***redacted***', $out['firstname']);
        $this->assertSame('***redacted***', $out['email']);
        $this->assertSame('***redacted***', $out['phone']);
        $this->assertSame('T1', $out['txnid'], 'non-PII fields stay full');
    }

    public function testHashFieldInPayloadIsAlwaysMaskedRegardlessOfMode(): void
    {
        $redactor = new PayloadRedactor(false);
        $payload = ['hash' => str_repeat('a', 128), 'txnid' => 'T1'];

        $out = $redactor->redact($payload);

        $this->assertStringContainsString('aaaaaaaa...len=128', $out['hash']);
    }

    public function testKeysNotInTheRulesPassThroughUnchanged(): void
    {
        $redactor = new PayloadRedactor(true);
        $payload = ['mihpayid' => 'M123', 'status' => 'success', 'udf5' => 'Magento_v.2.4.6'];

        $out = $redactor->redact($payload);

        $this->assertSame('M123', $out['mihpayid']);
        $this->assertSame('success', $out['status']);
        $this->assertSame('Magento_v.2.4.6', $out['udf5']);
    }
}
