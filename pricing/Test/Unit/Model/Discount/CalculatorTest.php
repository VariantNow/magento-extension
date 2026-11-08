<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Test\Unit\Model\Discount;

use PHPUnit\Framework\TestCase;
use Variant\Pricing\Model\Discount\Calculator;
use Variant\Pricing\Model\Discount\Line;
use Variant\Pricing\Model\Discount\SipHash;

class CalculatorTest extends TestCase
{
    private const KEY = '000102030405060708090a0b0c0d0e0f';
    private const RECIPE = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /**
     * @var Calculator
     */
    private $calculator;

    protected function setUp(): void
    {
        $this->calculator = new Calculator(new SipHash());
    }

    /**
     * @param array $rules
     * @param string $key
     * @return array
     */
    private function arm(array $rules, string $key = 'EXPA1:v2'): array
    {
        return [$key => ['on' => true, 'r' => $rules]];
    }

    /**
     * @param array $config
     * @param Line[] $lines
     * @param string[] $keys
     * @return array<string, float>
     */
    private function amounts(array $config, array $lines, array $keys = ['EXPA1:v2'], array $recKeys = []): array
    {
        return array_map(
            fn ($r) => $r['amount'],
            $this->calculator->calculate($config, $keys, $lines, $recKeys)
        );
    }

    /**
     * @return Line[]
     */
    private function cart(): array
    {
        return [
            new Line('1', 2, 68.00, '1', ['1', '24-MB01'], ['3', '4']),
            new Line('2', 1, 52.00, '10', ['10', 'MH01', 'MH01-XS-Black'], ['15']),
        ];
    }

    public function testPercentOffTheWholeOrder(): void
    {
        $result = $this->calculator->calculate(
            $this->arm([['t' => 'p', 'v' => 10, 's' => 'o', 'm' => '10% off']]),
            ['EXPA1:v2'],
            $this->cart()
        );

        $this->assertSame([
            '1' => ['amount' => 6.8, 'message' => '10% off', 'messages' => ['10% off']],
            '2' => ['amount' => 5.2, 'message' => '10% off', 'messages' => ['10% off']],
        ], $result);
    }

    public function testALineNamesEveryOfferThatDiscountedIt(): void
    {
        $result = $this->calculator->calculate(
            $this->arm([
                ['t' => 'p', 'v' => 30, 's' => 'p', 'p' => ['1'], 'q' => 3, 'm' => '3-pack: 30% off'],
                ['t' => 'p', 'v' => 10, 's' => 'o', 'm' => 'Spring test: 10% off'],
            ]),
            ['EXPA1:v2'],
            [new Line('a', 4, 136.0, '1', ['1'])]
        );

        // The browser check: 30% on 3 of 4 units, then 10% on what is left.
        $this->assertSame(41.14, $result['a']['amount']);
        $this->assertSame(['3-pack: 30% off', 'Spring test: 10% off'], $result['a']['messages']);
    }

    public function testFixedOrderAmountSplitsToTheCent(): void
    {
        $amounts = $this->amounts($this->arm([['t' => 'f', 'v' => 10, 's' => 'o']]), $this->cart());

        $this->assertSame(['1' => 5.67, '2' => 4.33], $amounts);
    }

    public function testTheBiggestOrderDiscountWins(): void
    {
        $config = $this->arm([['t' => 'f', 'v' => 5, 's' => 'o']])
            + $this->arm([['t' => 'p', 'v' => 20, 's' => 'o']], 'EXPB2:v1');

        $amounts = $this->amounts($config, $this->cart(), ['EXPA1:v2', 'EXPB2:v1']);

        $this->assertSame(24.0, round(array_sum($amounts), 2));
    }

    public function testProductRulesMatchIdSkuChildSkuOrCategory(): void
    {
        $amounts = $this->amounts($this->arm([
            ['t' => 'p', 'v' => 25, 's' => 'p', 'p' => ['24-MB01']],
            ['t' => 'f', 'v' => 3, 's' => 'p', 'p' => ['MH01-XS-Black']],
            ['t' => 'f', 'v' => 2, 's' => 'p', 'c' => ['15']],
        ]), $this->cart());

        // Product rules add up on a line; a fixed amount is one flat amount per line.
        $this->assertSame(['1' => 17.0, '2' => 5.0], $amounts);
    }

    public function testOnlyTheLatestArmOfAnExperimentCounts(): void
    {
        $config = $this->arm([['t' => 'p', 'v' => 50, 's' => 'o']], 'EXPA1:v1')
            + $this->arm([['t' => 'p', 'v' => 10, 's' => 'o']], 'EXPA1:v2');

        $this->assertSame(['EXPA1:v2'], $this->calculator->liveKeys(['EXPA1:v1', 'EXPA1:v2'], $config));
        $this->assertSame(['1' => 6.8, '2' => 5.2], $this->amounts($config, $this->cart(), ['EXPA1:v1', 'EXPA1:v2']));
    }

    public function testSwitchedOffArmsAndUnknownKeysGiveNothing(): void
    {
        $config = ['EXPA1:v2' => ['on' => false, 'r' => [['t' => 'p', 'v' => 10, 's' => 'o']]]];

        $this->assertSame([], $this->amounts($config, $this->cart(), ['EXPA1:v2', 'EXPZZ:v9']));
    }

    /**
     * The table in the coding agent's `discounts` skill: a 3-pack at 30% and a 2-pack at
     * 20% on one product group; leftovers are not discounted.
     *
     * @return array
     */
    public static function packTable(): array
    {
        return [
            '2 units' => [2, 2 * 10 * 0.20],
            '3 units' => [3, 3 * 10 * 0.30],
            '4 units' => [4, 3 * 10 * 0.30],
            '5 units' => [5, 3 * 10 * 0.30 + 2 * 10 * 0.20],
            '6 units' => [6, 6 * 10 * 0.30],
            '8 units' => [8, 6 * 10 * 0.30 + 2 * 10 * 0.20],
        ];
    }

    /**
     * @dataProvider packTable
     * @param int $units
     * @param float $expected
     */
    public function testPooledPacksFollowTheSkillTable(int $units, float $expected): void
    {
        $config = $this->arm([
            ['t' => 'p', 'v' => 30, 's' => 'p', 'p' => ['1'], 'q' => 3],
            ['t' => 'p', 'v' => 20, 's' => 'p', 'p' => ['1'], 'q' => 2],
        ]);

        $amounts = $this->amounts($config, [new Line('a', $units, 10.0 * $units, '1', ['1'])]);

        $this->assertEqualsWithDelta($expected, $amounts['a'] ?? 0.0, 0.001);
    }

    public function testPooledPacksCountUnitsAcrossProducts(): void
    {
        $config = $this->arm([['t' => 'p', 'v' => 30, 's' => 'p', 'p' => ['1', '2'], 'q' => 3]]);

        $amounts = $this->amounts($config, [
            new Line('a', 2, 20.0, '1', ['1']),
            new Line('b', 1, 10.0, '2', ['2']),
        ]);

        $this->assertSame(['a' => 6.0, 'b' => 3.0], $amounts);
    }

    public function testSameProductPacksNeverMixProducts(): void
    {
        $config = $this->arm([['t' => 'p', 'v' => 30, 's' => 'p', 'p' => ['1', '2'], 'sq' => 2]]);

        $this->assertSame([], $this->amounts($config, [
            new Line('a', 1, 10.0, '1', ['1']),
            new Line('b', 1, 10.0, '2', ['2']),
        ]), '1 A + 1 B: different products never fill one pack');

        $amounts = $this->amounts($config, [
            new Line('a', 3, 30.0, '1', ['1']),
            new Line('b', 2, 20.0, '2', ['2']),
        ]);
        $this->assertSame(['a' => 6.0, 'b' => 6.0], $amounts, '3 A + 2 B: 2 A and 2 B, 1 A at full price');
    }

    public function testSameProductPackCountsVariantsOfOneConfigurable(): void
    {
        $config = $this->arm([['t' => 'p', 'v' => 30, 's' => 'p', 'p' => ['10'], 'sq' => 2]]);

        // Two cart items of the same configurable (size S and M) are one product.
        $amounts = $this->amounts($config, [
            new Line('s', 1, 52.0, '10', ['10', 'MH01-S']),
            new Line('m', 1, 52.0, '10', ['10', 'MH01-M']),
        ]);

        $this->assertSame(['s' => 15.6, 'm' => 15.6], $amounts);
    }

    public function testFixedPackAmountIsSplitByUnitAndAddsUp(): void
    {
        $config = $this->arm([['t' => 'f', 'v' => 5, 's' => 'p', 'p' => ['1'], 'q' => 3]]);

        $amounts = $this->amounts($config, [
            new Line('s', 1, 45.0, '1', ['1']),
            new Line('m', 1, 45.0, '1', ['1']),
            new Line('l', 1, 45.0, '1', ['1']),
        ]);

        $this->assertSame(5.0, round(array_sum($amounts), 2));
        $this->assertSame(['s' => 1.67, 'm' => 1.67, 'l' => 1.66], $amounts);
    }

    public function testCartValueTierCountsOnlyTheRestOfTheCart(): void
    {
        // A free gift (100% off product 9) once the rest of the cart reaches $50, at most 1 unit.
        $config = $this->arm([['t' => 'p', 'v' => 100, 's' => 'p', 'p' => ['9'], 'cv' => [[50, 1]]]]);
        $gift = new Line('gift', 2, 30.0, '9', ['9']);

        $this->assertSame([], $this->amounts($config, [$gift, new Line('x', 1, 40.0, '1', ['1'])]));
        $this->assertSame(
            ['gift' => 15.0],
            $this->amounts($config, [$gift, new Line('x', 1, 60.0, '1', ['1'])]),
            'tier reached: one unit of two free'
        );
    }

    public function testRecipeRulesNeedAVerifiedReceiptOnTheRealProduct(): void
    {
        $mac = (new SipHash())->hex(self::KEY, 'v1|aaaaaaaa|123');
        $config = $this->arm([['t' => 'p', 'v' => 15, 's' => 'r', 'rid' => self::RECIPE]]);
        $lines = [
            new Line('rec', 1, 20.0, '123', ['123'], [], self::RECIPE . '.1.' . $mac),
            new Line('moved', 1, 80.0, '999', ['999'], [], self::RECIPE . '.1.' . $mac),
            new Line('plain', 1, 50.0, '123', ['123']),
        ];

        $this->assertSame(['rec' => 3.0], $this->amounts($config, $lines, ['EXPA1:v2'], ['1' => self::KEY]));
        $this->assertSame([], $this->amounts($config, $lines, ['EXPA1:v2'], []), 'no signing keys');
    }

    public function testCheckoutCardRulesOnlySeeCardReceipts(): void
    {
        $mac = (new SipHash())->hex(self::KEY, 'v1c|aaaaaaaa|123');
        $config = $this->arm([['t' => 'p', 'v' => 20, 's' => 'p', 'p' => ['123'], 'co' => 1]]);
        $lines = [
            new Line('card', 1, 20.0, '123', ['123'], [], self::RECIPE . '.1.' . $mac . '.c'),
            new Line('plain', 1, 20.0, '123', ['123']),
        ];

        $this->assertSame(['card' => 4.0], $this->amounts($config, $lines, ['EXPA1:v2'], ['1' => self::KEY]));
    }

    public function testSubscriptionOnlyRulesNeverMatchOnMagento(): void
    {
        $config = $this->arm([
            ['t' => 'p', 'v' => 30, 's' => 'o', 'pt' => 's'],
            ['t' => 'p', 'v' => 10, 's' => 'o', 'pt' => 'o'],
        ]);

        $this->assertSame(['1' => 6.8, '2' => 5.2], $this->amounts($config, $this->cart()));
    }

    public function testOrderDiscountAppliesToWhatProductDiscountsLeft(): void
    {
        $config = $this->arm([
            ['t' => 'p', 'v' => 50, 's' => 'p', 'p' => ['1']],
            ['t' => 'p', 'v' => 10, 's' => 'o'],
        ]);

        // Line 1: 34.00 product + 10% of the remaining 34.00; line 2: 10% of 52.00.
        $this->assertSame(['1' => 37.4, '2' => 5.2], $this->amounts($config, $this->cart()));
    }

    public function testNeverDiscountsMoreThanTheStoreLeft(): void
    {
        $config = $this->arm([['t' => 'f', 'v' => 10, 's' => 'p', 'p' => ['1']]]);

        $amounts = $this->amounts($config, [new Line('1', 1, 20.0, '1', ['1'], [], null, 15.0)]);

        $this->assertSame(['1' => 5.0], $amounts);
    }

    /**
     * The ticket's ladder: 2 items 10%, 3 items 15%, 4+ items 20% — packs.test.ts.
     *
     * @param array $overrides
     * @return array
     */
    private function ladder(array $overrides = []): array
    {
        return $overrides + [
            't' => 'p', 'v' => 10, 's' => 'p', 'p' => ['10', '20'], 'q' => 2,
            'qt' => [[3, 15], [4, 20]], 'm' => 'ladder',
        ];
    }

    /**
     * @return array
     */
    public static function ladderCases(): array
    {
        return [[1, []], [2, ['A' => 9.0]], [3, ['A' => 20.25]], [4, ['A' => 36.0]], [5, ['A' => 45.0]]];
    }

    /**
     * @dataProvider ladderCases
     * @param int $units
     * @param array $expected
     */
    public function testQuantityTiersFollowTheLadder(int $units, array $expected): void
    {
        $lines = [new Line('A', $units, 45.0 * $units, '10', ['10'])];

        $this->assertSame($expected, $this->amounts($this->arm([$this->ladder()]), $lines));
    }

    public function testQuantityTiersCountUnitsAcrossProductsAndDiscountEveryOne(): void
    {
        $lines = [new Line('A', 2, 90.0, '10', ['10']), new Line('B', 3, 60.0, '20', ['20'])];

        $this->assertSame(['A' => 18.0, 'B' => 12.0], $this->amounts($this->arm([$this->ladder()]), $lines));
    }

    public function testSameProductQuantityTiersGiveEachProductItsOwnTier(): void
    {
        $same = $this->ladder(['sq' => 2]);
        unset($same['q']);

        // 2 + 2 of different products: no product reaches 3, so each gets its own 2-pack at 10%.
        $mixed = [new Line('A', 2, 90.0, '10', ['10']), new Line('B', 2, 90.0, '20', ['20'])];
        $this->assertSame(['A' => 9.0, 'B' => 9.0], $this->amounts($this->arm([$same]), $mixed));

        // 4 of A reach 20%, 3 of B reach 15%.
        $tiers = [new Line('A', 4, 180.0, '10', ['10']), new Line('B', 3, 135.0, '20', ['20'])];
        $this->assertSame(['A' => 36.0, 'B' => 20.25], $this->amounts($this->arm([$same]), $tiers));
    }

    public function testAFixedTierIsChargedOnceForTheWholeSet(): void
    {
        $fixed = $this->ladder(['t' => 'f', 'v' => 5, 'qt' => [[3, 12]]]);

        $amounts = $this->amounts($this->arm([$fixed]), [new Line('A', 3, 135.0, '10', ['10'])]);

        $this->assertSame(['A' => 12.0], $amounts);
    }

    public function testTiersApplyOnAZeroPercentBase(): void
    {
        $fromThree = $this->arm([$this->ladder(['v' => 0])]);

        $this->assertSame([], $this->amounts($fromThree, [new Line('A', 2, 90.0, '10', ['10'])]));
        $this->assertSame(['A' => 20.25], $this->amounts($fromThree, [new Line('A', 3, 135.0, '10', ['10'])]));
    }

    public function testMalformedTiersAreIgnored(): void
    {
        $bad = $this->arm([$this->ladder(['qt' => [[NAN, 50], [3, -1], 'x']])]);

        // Only the base 2-pack at 10%: 2 of the 3 units, 9.00.
        $this->assertSame(['A' => 9.0], $this->amounts($bad, [new Line('A', 3, 135.0, '10', ['10'])]));
    }

    /**
     * A discount stamp, as the storefront backend mints it.
     *
     * @param string $product
     * @param string $key
     * @param string $scope 's' or 'sc' (covered)
     * @return string
     */
    private function stamp(string $product, string $key = 'EXPA1:v2', string $scope = 's'): string
    {
        $mac = (new SipHash())->hex(self::KEY, ($scope === 's' ? 'v1s' : 'v1sc') . '|' . $key . '|' . $product);
        return $scope . '.1.' . $mac;
    }

    /**
     * @param int $st
     * @return array
     */
    private function stampedArm(int $st = 1): array
    {
        $arm = $this->arm([['t' => 'p', 'v' => 20, 's' => 'p', 'p' => ['10', '20'], 'm' => 'page']]);
        if ($st) {
            $arm['EXPA1:v2']['st'] = 1;
        }
        return $arm;
    }

    public function testStampedArmsOnlyDiscountLinesWithAValidStampForTheirKey(): void
    {
        $lines = [
            new Line('A', 1, 45.0, '10', ['10'], [], $this->stamp('10')),
            new Line('B', 1, 45.0, '20', ['20'], [], null),
        ];

        $this->assertSame(['A' => 9.0], $this->amounts($this->stampedArm(), $lines, ['EXPA1:v2'], ['1' => self::KEY]));
    }

    public function testAStampForAnotherKeyOrProductIsRefused(): void
    {
        $otherKey = [new Line('A', 1, 45.0, '10', ['10'], [], $this->stamp('10', 'EXPB2:v1'))];
        $otherProduct = [new Line('A', 1, 45.0, '10', ['10'], [], $this->stamp('20'))];

        $this->assertSame([], $this->amounts($this->stampedArm(), $otherKey, ['EXPA1:v2'], ['1' => self::KEY]));
        $this->assertSame([], $this->amounts($this->stampedArm(), $otherProduct, ['EXPA1:v2'], ['1' => self::KEY]));
    }

    public function testArmsWithoutStampsAreUnchanged(): void
    {
        $lines = [new Line('A', 1, 45.0, '10', ['10'])];

        $this->assertSame(['A' => 9.0], $this->amounts($this->stampedArm(0), $lines, ['EXPA1:v2'], ['1' => self::KEY]));
    }

    public function testAnotherCartKeysCoveredStampCountsButNeverAPlainOne(): void
    {
        $recKeys = ['1' => self::KEY];
        // The cart also holds product 10 stamped by this arm (line B), so line A competes.
        $own = new Line('B', 1, 45.0, '10', ['10'], [], $this->stamp('10'));
        $covered = new Line('A', 1, 45.0, '10', ['10'], [], $this->stamp('10', 'EXPB2:v1', 'sc'));
        $plain = new Line('A', 1, 45.0, '10', ['10'], [], $this->stamp('10', 'EXPB2:v1'));

        $both = ['EXPA1:v2', 'EXPB2:v1'];
        $this->assertArrayHasKey('A', $this->amounts($this->stampedArm(), [$covered, $own], $both, $recKeys));
        // The other key isn't on the cart, or its stamp is a plain one.
        $this->assertArrayNotHasKey('A', $this->amounts($this->stampedArm(), [$covered, $own], ['EXPA1:v2'], $recKeys));
        $this->assertArrayNotHasKey('A', $this->amounts($this->stampedArm(), [$plain, $own], $both, $recKeys));
        // This arm's key lingers on the cart but none of its product is in it.
        $this->assertSame([], $this->amounts($this->stampedArm(), [$covered], $both, $recKeys));
    }
}
