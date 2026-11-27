<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Service\SubmerchantRowPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The presenter turns a raw submerchant-mapping row into the display strings the A7-2 admin
 * grid shows. Pure (no DB, no clock). Its security-relevant job is masking the PayU child
 * merchant key + MID to last-4 in the grid so the full routing identifier never appears in a
 * list view — full values are only on the ACL-gated edit form.
 */
class SubmerchantRowPresenterTest extends TestCase
{
    private SubmerchantRowPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new SubmerchantRowPresenter();
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function secretProvider(): array
    {
        return [
            'null is an em dash'        => [null, '—'],
            'empty is an em dash'       => ['', '—'],
            'long key shows last 4'     => ['MERCHANTKEY1234', '•••••••••••1234'],
            'typical sandbox key'       => ['CHLD01', '••LD01'],
            'exactly four fully masked' => ['ABCD', '••••'],
            'shorter than four masked'  => ['AB', '••'],
            'single char masked'        => ['A', '•'],
        ];
    }

    /**
     * @dataProvider secretProvider
     * @param string|null $value
     * @param string $expected
     */
    public function testMaskSecretRevealsOnlyTheLastFourCharacters(?string $value, string $expected): void
    {
        $this->assertSame($expected, $this->presenter->maskSecret($value));
    }

    public function testKycLabelTitleCasesTheStatus(): void
    {
        $this->assertSame('Pending', $this->presenter->kycLabel('pending'));
        $this->assertSame('Verified', $this->presenter->kycLabel('verified'));
        $this->assertSame('Rejected', $this->presenter->kycLabel('rejected'));
    }

    public function testKycLabelIsAnEmDashWhenEmpty(): void
    {
        $this->assertSame('—', $this->presenter->kycLabel(''));
    }

    public function testActiveLabelReflectsTheFlag(): void
    {
        $this->assertSame('Active', $this->presenter->activeLabel(1));
        $this->assertSame('Inactive', $this->presenter->activeLabel(0));
    }
}
