<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Service\SplitHashBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The postservice hash is what authenticates every split call. Field order and the
 * pipe delimiter are contractual — any drift returns AGG-300 from PayU.
 */
class SplitHashBuilderTest extends TestCase
{
    private SplitHashBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SplitHashBuilder();
    }

    public function testHashIsKeyCommandVar1SaltInThatOrder(): void
    {
        $hash = $this->builder->postservice('K', 'C', 'V', 'S');
        $this->assertSame(hash('sha512', 'K|C|V|S'), $hash);
    }

    public function testReorderingFieldsChangesTheHash(): void
    {
        // Guards against a future refactor silently swapping var1/salt etc.
        $correct = $this->builder->postservice('K', 'C', 'V', 'S');
        $this->assertNotSame(hash('sha512', 'K|C|S|V'), $correct);
        $this->assertNotSame(hash('sha512', 'C|K|V|S'), $correct);
    }

    public function testGetSplitInfoUsesPayuIdAsVar1(): void
    {
        // get_split_info hashes the payuId in the var1 slot (KB §6c).
        $payuId = '100000000000000002';
        $this->assertSame(
            hash('sha512', 'PRNT01|get_split_info|' . $payuId . '|saltvalue'),
            $this->builder->postservice('PRNT01', 'get_split_info', $payuId, 'saltvalue')
        );
    }

    public function testOutputIsLowercaseHexSha512(): void
    {
        $hash = $this->builder->postservice('K', 'C', 'V', 'S');
        $this->assertSame(128, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $hash);
    }
}
