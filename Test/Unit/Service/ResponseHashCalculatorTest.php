<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Service;

use RKD\PayuSplit\Service\ResponseHashCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The calculator is the only place PayU's response-hash formula lives.
 * Both the diagnostic logger and the split-aware validation plugin route through it.
 *
 * Source of truth: https://docs.payu.in/docs/hashing-request-and-response
 *
 *   Classic (splitInfo absent):
 *     sha512(SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
 *
 *   Split-aware (splitInfo present in response — merchant has split enabled):
 *     sha512(SALT|status|splitInfo||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
 *
 *   Additional charges variant (either form): prepend "additional_charges|" to the string above.
 *
 * Note on the "5 empty pipes": PayU's *current* spec collapses udf10..udf6 into 5 empty
 * positions in the response hash regardless of whether splitInfo is present. The upstream
 * PayUIndia\Payu\Model\Payu module still feeds udf10..udf6 actual values — for Magento
 * payloads where those are empty (the common case) the strings are byte-identical, but
 * if any of udf6..udf10 ever carry a value, upstream will mismatch even classic responses.
 */
class ResponseHashCalculatorTest extends TestCase
{
    private const SALT = 'sandbox-salt-1234';
    private const KEY  = 'gtKFFx';

    private ResponseHashCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ResponseHashCalculator();
    }

    public function testClassicFormulaWhenSplitInfoAbsent(): void
    {
        $payload = $this->basePayload();
        // Pipe layout: SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key
        // udf4=udf3=udf2='' → "Magento_v.2.4.6||||udf1value" (3 empties, 4 pipes between values)
        $expected = strtolower(hash('sha512',
            self::SALT . '|success||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testSplitFormulaWhenSplitInfoPresent(): void
    {
        $splitInfo = '{"splitStatus":"splitNotReceived","splitSegments":[]}';
        $payload = $this->basePayload();
        $payload['splitInfo'] = $splitInfo;

        $expected = strtolower(hash('sha512',
            self::SALT . '|success|' . $splitInfo . '||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testEmptySplitInfoStringStillTriggersSplitFormula(): void
    {
        // PayU's behaviour for an empty splitInfo string is undocumented. We treat
        // *presence* of the key as the trigger — it's what their gateway does.
        $payload = $this->basePayload();
        $payload['splitInfo'] = '';

        // Empty splitInfo collapses to: SALT|status||||||||udf5... (status, then |, '', |, six pipes, udf5)
        $expected = strtolower(hash('sha512',
            self::SALT . '|success|||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testMissingUdfFieldsTreatedAsEmptyString(): void
    {
        // Real PayU responses sometimes drop trailing udf keys entirely instead
        // of sending them empty. The calculator must not throw under PHP 8.x
        // strict undefined-array-key warnings — that exact behaviour is what
        // bites the upstream module today.
        $payload = $this->basePayload();
        unset($payload['udf3'], $payload['udf4'], $payload['udf5']);

        // udf5/4/3 missing → empty positions; udf2 still empty; udf1 still 'udf1value'
        // status separator (1) + 5 empty placeholders (5) + 4 empty udf5/4/3/2 (4) = 10 pipes
        $expected = strtolower(hash('sha512',
            self::SALT . '|success||||||||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testAdditionalChargesSnakeCasePrependsToHashString(): void
    {
        $payload = $this->basePayload();
        $payload['additional_charges'] = '12.50';

        $expected = strtolower(hash('sha512',
            '12.50|' . self::SALT . '|success||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testAdditionalChargesCamelCaseUpstreamAliasAlsoRecognised(): void
    {
        // Upstream PayUIndia\Payu\Model\Payu reads $returnParams['additionalCharges']
        // (camelCase). PayU's documented field is additional_charges (snake_case).
        // Either could appear in the wild depending on PayU's response shape; the
        // calculator accepts both, snake_case wins if both are present.
        $payload = $this->basePayload();
        $payload['additionalCharges'] = '12.50';

        $expected = strtolower(hash('sha512',
            '12.50|' . self::SALT . '|success||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testZeroAdditionalChargesNotPrepended(): void
    {
        // Upstream guards `if ($additionalCharges > 0)`. Mirror that — zero means
        // "no surcharge" and the prefix would corrupt the hash if added.
        $payload = $this->basePayload();
        $payload['additional_charges'] = '0';

        $expected = strtolower(hash('sha512',
            self::SALT . '|success||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testSplitFormulaWithAdditionalCharges(): void
    {
        $splitInfo = '{"splitStatus":"splitDone","splitSegments":[{"merchantId":"M1","amount":"100"}]}';
        $payload = $this->basePayload();
        $payload['splitInfo'] = $splitInfo;
        $payload['additional_charges'] = '5.00';

        $expected = strtolower(hash('sha512',
            '5.00|' . self::SALT . '|success|' . $splitInfo . '||||||Magento_v.2.4.6||||udf1value|john@example.com|John|59|692.56|59|' . self::KEY
        ));

        $this->assertSame($expected, $this->calc->compute($payload, self::SALT, self::KEY));
    }

    public function testReturnsLowercaseSha512(): void
    {
        $hash = $this->calc->compute($this->basePayload(), self::SALT, self::KEY);
        $this->assertSame(128, strlen($hash));
        $this->assertSame(strtolower($hash), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $hash);
    }

    public function testRealRecordedPayloadFromTxn59ProducesDeterministicHash(): void
    {
        // Smoke test: the recorded payload from var/log/payu.log line 7 (txnid 59).
        // We can't assert against PayU's hash (we don't know the real sandbox salt),
        // but we can assert the calculator runs through the real payload shape
        // without throwing and produces a 128-char hex digest.
        $recorded = [
            'mihpayid' => '100000000000000005',
            'mode' => 'CC',
            'status' => 'success',
            'unmappedstatus' => 'captured',
            'key' => 'PRNT01',
            'txnid' => '59',
            'amount' => '692.56',
            'productinfo' => '59',
            'firstname' => 'John',
            'email' => 'john.doe@example.com',
            'udf1' => 'sess0000000000000000000001',
            'udf2' => '',
            'udf3' => '',
            'udf4' => '',
            'udf5' => 'Magento_v.2.4.6',
            'udf6' => '',
            'udf7' => '',
            'udf8' => '',
            'udf9' => '',
            'udf10' => '',
            'splitInfo' => '{"splitStatus":"splitNotReceived","splitSegments":[]}',
        ];

        $hash = $this->calc->compute($recorded, 'arbitrary-salt', 'PRNT01');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $hash);
    }

    /**
     * @return array<string, string>
     */
    private function basePayload(): array
    {
        return [
            'status'      => 'success',
            'key'         => self::KEY,
            'txnid'       => '59',
            'amount'      => '692.56',
            'productinfo' => '59',
            'firstname'   => 'John',
            'email'       => 'john@example.com',
            'udf1'        => 'udf1value',
            'udf2'        => '',
            'udf3'        => '',
            'udf4'        => '',
            'udf5'        => 'Magento_v.2.4.6',
            'udf6'        => '',
            'udf7'        => '',
            'udf8'        => '',
            'udf9'        => '',
            'udf10'       => '',
        ];
    }
}
