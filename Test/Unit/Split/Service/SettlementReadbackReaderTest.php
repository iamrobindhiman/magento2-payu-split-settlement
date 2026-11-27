<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Service\SettlementReadbackReader;
use PHPUnit\Framework\TestCase;

/**
 * The INV-18 readback reader: extracts a child segment's SETTLED amount from a
 * get_aggregator_transactions response. The discriminator is deliberately conservative — only
 * an explicit settlement-amount field counts as a signal; a bare split allocation does not (the
 * UAT-safe behaviour: E-07 sandbox exposes allocation but no settlement, so the recon never
 * wrongly releases). Tests pin: a settled row → found(paise); allocation-only → notFound;
 * non-array/print_r body → notFound; wrong txn / wrong child → notFound; wrapper-keyed list.
 */
class SettlementReadbackReaderTest extends TestCase
{
    private SettlementReadbackReader $reader;

    protected function setUp(): void
    {
        $this->reader = new SettlementReadbackReader();
    }

    /**
     * @param mixed $raw
     * @return PayuApiResponse
     */
    private function response(mixed $raw): PayuApiResponse
    {
        return PayuApiResponse::fromFields(['status' => '1'], is_array($raw) ? 'json' : 'unknown', $raw);
    }

    public function testSettledRowYieldsFoundInPaise(): void
    {
        $raw = [
            'transactions' => [
                [
                    'mihpayid' => '100000000000000001',
                    'splitInfo' => [
                        'CHLD01' => ['splitAmount' => '679.32', 'settledAmount' => '669.32'],
                        'PRNT01' => ['splitAmount' => '119.88', 'settledAmount' => '119.88'],
                    ],
                ],
            ],
        ];

        $result = $this->reader->read($this->response($raw), '100000000000000001', 'CHLD01');

        $this->assertTrue($result->hasSettlementSignal);
        $this->assertSame(66932, $result->settledPaise);
    }

    public function testAllocationOnlyIsNotASettlementSignal(): void
    {
        // The UAT shape: split allocation present, no settlement field. Must NOT release.
        $raw = [
            [
                'mihpayid' => '100000000000000001',
                'splitInfo' => [
                    'CHLD01' => ['splitAmount' => '679.32'],
                ],
            ],
        ];

        $result = $this->reader->read($this->response($raw), '100000000000000001', 'CHLD01');

        $this->assertFalse($result->hasSettlementSignal);
        $this->assertSame(0, $result->settledPaise);
    }

    public function testNonArrayBodyYieldsNotFound(): void
    {
        $printRBody = $this->response('<pre>Array ( [status] => 1 )</pre>');

        $result = $this->reader->read($printRBody, '100000000000000001', 'CHLD01');

        $this->assertFalse($result->hasSettlementSignal);
    }

    public function testUnmatchedTransactionYieldsNotFound(): void
    {
        $raw = [['mihpayid' => 'SOME_OTHER_TXN', 'splitInfo' => ['CHLD01' => ['settledAmount' => '669.32']]]];

        $result = $this->reader->read($this->response($raw), '100000000000000001', 'CHLD01');

        $this->assertFalse($result->hasSettlementSignal);
    }

    public function testUnmatchedChildKeyYieldsNotFound(): void
    {
        $raw = [['mihpayid' => 'TXN1', 'splitInfo' => ['OTHERCHILD' => ['settledAmount' => '669.32']]]];

        $result = $this->reader->read($this->response($raw), 'TXN1', 'CHLD01');

        $this->assertFalse($result->hasSettlementSignal);
    }

    public function testTopLevelListWithoutWrapperKeyIsTraversed(): void
    {
        $raw = [
            ['mihpayid' => 'TXN_A', 'splitInfo' => ['CHLD01' => ['released_amount' => '500.00']]],
            ['mihpayid' => 'TXN_B', 'splitInfo' => ['CHLD01' => ['released_amount' => '123.45']]],
        ];

        $result = $this->reader->read($this->response($raw), 'TXN_B', 'CHLD01');

        $this->assertTrue($result->hasSettlementSignal);
        $this->assertSame(12345, $result->settledPaise);
    }

    public function testEmptyMihpayidOrChildKeyYieldsNotFound(): void
    {
        $raw = [['mihpayid' => 'TXN1', 'splitInfo' => ['CHLD01' => ['settledAmount' => '1.00']]]];

        $this->assertFalse($this->reader->read($this->response($raw), '', 'CHLD01')->hasSettlementSignal);
        $this->assertFalse($this->reader->read($this->response($raw), 'TXN1', '')->hasSettlementSignal);
    }

    public function testNonNumericSettledAmountYieldsNotFound(): void
    {
        $raw = [['mihpayid' => 'TXN1', 'splitInfo' => ['CHLD01' => ['settledAmount' => 'N/A']]]];

        $result = $this->reader->read($this->response($raw), 'TXN1', 'CHLD01');

        $this->assertFalse($result->hasSettlementSignal);
    }

    public function testZeroSettledAmountIsTreatedAsNoSignal(): void
    {
        // A present-but-zero settled field is "not settled yet" / a calibration miss, never a real
        // ₹0 release — so it must NOT signal (which would mass-freeze positive segments).
        $raw = [['mihpayid' => 'TXN1', 'splitInfo' => ['CHLD01' => ['settledAmount' => '0.00']]]];

        $result = $this->reader->read($this->response($raw), 'TXN1', 'CHLD01');

        $this->assertFalse($result->hasSettlementSignal);
        $this->assertSame(0, $result->settledPaise);
    }
}
