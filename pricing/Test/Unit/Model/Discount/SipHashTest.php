<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Test\Unit\Model\Discount;

use PHPUnit\Framework\TestCase;
use Variant\Pricing\Model\Discount\Line;
use Variant\Pricing\Model\Discount\Receipts;
use Variant\Pricing\Model\Discount\SipHash;

/**
 * Same reference table as packages/shared/tests/rec-token-siphash.test.ts and the Shopify
 * function's siphash.test.ts: these vectors are what pin the three copies together.
 */
class SipHashTest extends TestCase
{
    private const KEY = '000102030405060708090a0b0c0d0e0f';
    private const RECIPE = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /**
     * @return array
     */
    public static function vectors(): array
    {
        return [
            [0, '310e0edd47db6f72'],
            [1, 'fd67dc93c539f874'],
            [2, '5a4fa9d909806c0d'],
            [3, '2d7efbd796666785'],
            [7, '37d1018bf50002ab'],
            [8, '6224939a79f5f593'],
            [15, 'e545be4961ca29a1'],
        ];
    }

    /**
     * @dataProvider vectors
     * @param int $length
     * @param string $expected
     */
    public function testMatchesTheReferenceVectors(int $length, string $expected): void
    {
        $this->assertSame($expected, (new SipHash())->hash(range(0, 15), $length ? range(0, $length - 1) : []));
    }

    public function testMatchesTheGoldenCheckoutMacFromPackagesShared(): void
    {
        $this->assertSame('48bbfa143041edd4', (new SipHash())->hex(self::KEY, 'v1c|aaaaaaaa|123'));
    }

    public function testRefusesAKeyThatIsNotSixteenBytes(): void
    {
        $this->assertNull((new SipHash())->hex('00ff', 'abc'));
    }

    public function testVerifiesAReceiptOnlyForItsRecipeAndProduct(): void
    {
        $sip = new SipHash();
        $mac = $sip->hex(self::KEY, 'v1|aaaaaaaa|123');
        $receipts = new Receipts($sip, ['1' => self::KEY]);
        $line = fn (string $productId) => new Line(
            'l' . $productId,
            1,
            10.0,
            $productId,
            [$productId],
            [],
            self::RECIPE . '.1.' . $mac
        );

        $this->assertTrue($receipts->forRecipe($line('123'), self::RECIPE));
        $this->assertFalse($receipts->forRecipe($line('124'), self::RECIPE), 'moved to another product');
        $this->assertFalse($receipts->forRecipe($line('123'), 'bbbbbbbb-other'), 'another recipe');
        $this->assertFalse((new Receipts($sip, []))->forRecipe($line('123'), self::RECIPE), 'unknown key');
        $this->assertFalse($receipts->fromCheckoutCard($line('123')), 'not a checkout receipt');
    }
}
