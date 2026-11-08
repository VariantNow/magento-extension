<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Test\Unit\Model\Discount;

use PHPUnit\Framework\TestCase;
use Variant\Pricing\Model\Discount\Bundles;
use Variant\Pricing\Model\Discount\Line;

/**
 * Mirrors the Shopify bundle discount Function's tests (discount-function/src/bundles.test.ts).
 */
class BundlesTest extends TestCase
{
    private const B1 = 'b1';

    /**
     * @var Bundles
     */
    private $bundles;

    protected function setUp(): void
    {
        $this->bundles = new Bundles();
    }

    /**
     * A cart item tagged with a bundle add.
     *
     * @param string $id
     * @param string $productId
     * @param int $quantity
     * @param float $unitPrice
     * @param string|null $instance
     * @param string|null $bundleId
     * @param float $existing
     * @return Line
     */
    private function line(
        string $id,
        string $productId,
        int $quantity,
        float $unitPrice,
        ?string $instance = 'i1',
        ?string $bundleId = self::B1,
        float $existing = 0.0
    ): Line {
        return new Line(
            $id,
            $quantity,
            $quantity * $unitPrice,
            $productId,
            [$productId],
            [],
            null,
            $existing,
            false,
            $bundleId,
            $instance
        );
    }

    /**
     * @param array $bundle
     * @return array
     */
    private function config(array $bundle): array
    {
        return ['bundles' => [self::B1 => $bundle]];
    }

    /**
     * @param array $config
     * @param Line[] $lines
     * @return array<string, float>
     */
    private function amounts(array $config, array $lines): array
    {
        return array_map(fn ($r) => $r['amount'], $this->bundles->calculate($config, $lines));
    }

    private function fixedBundle(): array
    {
        // 5 briefs at $30 + 1 bag at $20 = $170, sold for $115.
        return ['t' => 'f', 'v' => 115, 'n' => '5 Pack', 'i' => ['10' => 5, '20' => 1]];
    }

    public function testFixedPriceTakesTheConfiguredUnitsDownToTheBundlePrice(): void
    {
        $result = $this->bundles->calculate(
            $this->config($this->fixedBundle()),
            [$this->line('a', '10', 5, 30), $this->line('b', '20', 1, 20)]
        );

        $this->assertEqualsWithDelta(55.0, $result['a']['amount'] + $result['b']['amount'], 0.001);
        // Spread by what each line's share costs: 150 of 170 and 20 of 170.
        $this->assertSame(48.53, $result['a']['amount']);
        $this->assertSame(6.47, $result['b']['amount']);
        $this->assertSame(['5 Pack discount'], $result['a']['messages']);
    }

    public function testFixedPriceGivesNothingWhenAComponentWasRemoved(): void
    {
        $this->assertSame([], $this->amounts($this->config($this->fixedBundle()), [$this->line('a', '10', 5, 30)]));
    }

    public function testFixedPriceGivesNothingWhenAComponentIsShort(): void
    {
        $this->assertSame([], $this->amounts(
            $this->config($this->fixedBundle()),
            [$this->line('a', '10', 4, 30), $this->line('b', '20', 1, 20)]
        ));
    }

    public function testAComponentSplitAcrossTwoItemsCounts(): void
    {
        $result = $this->amounts(
            $this->config($this->fixedBundle()),
            [$this->line('a', '10', 3, 30), $this->line('b', '20', 1, 20), $this->line('c', '10', 2, 30)]
        );

        $this->assertEqualsWithDelta(55.0, array_sum($result), 0.001);
    }

    public function testExtraUnitsPayFullPrice(): void
    {
        // The shopper raised the briefs to 12 after adding the bundle: only 5 count.
        $result = $this->amounts(
            $this->config($this->fixedBundle()),
            [$this->line('a', '10', 12, 30), $this->line('b', '20', 1, 20)]
        );

        $this->assertEqualsWithDelta(55.0, array_sum($result), 0.001);
    }

    public function testATaggedItemOutsideTheCompositionIsLeftAlone(): void
    {
        $result = $this->amounts(
            $this->config($this->fixedBundle()),
            [$this->line('a', '10', 5, 30), $this->line('b', '20', 1, 20), $this->line('c', '99', 1, 40)]
        );

        $this->assertArrayNotHasKey('c', $result);
        $this->assertEqualsWithDelta(55.0, array_sum($result), 0.001);
    }

    public function testFixedPriceGivesNothingWhenNotBelowTheComponentTotal(): void
    {
        $bundle = ['v' => 170] + $this->fixedBundle();

        $this->assertSame([], $this->amounts(
            $this->config($bundle),
            [$this->line('a', '10', 5, 30), $this->line('b', '20', 1, 20)]
        ));
    }

    public function testEachAddOfTheSameBundleIsPricedOnItsOwn(): void
    {
        $result = $this->amounts($this->config($this->fixedBundle()), [
            $this->line('a', '10', 5, 30, 'i1'),
            $this->line('b', '20', 1, 20, 'i1'),
            $this->line('c', '10', 5, 30, 'i2'),
            $this->line('d', '20', 1, 20, 'i2'),
        ]);

        $this->assertEqualsWithDelta(110.0, array_sum($result), 0.001);
        $this->assertSame($result['a'], $result['c']);
    }

    public function testFixedPriceNeverTakesMoreThanTheStoreRulesLeft(): void
    {
        $result = $this->amounts(
            $this->config($this->fixedBundle()),
            [$this->line('a', '10', 5, 30, 'i1', self::B1, 140.0), $this->line('b', '20', 1, 20)]
        );

        $this->assertSame(10.0, $result['a']);
    }

    public function testLegacyFixedPriceDerivesAPercentageFromTheLiveSubtotal(): void
    {
        $result = $this->amounts(
            $this->config(['t' => 'f', 'v' => 80, 'n' => 'Old']),
            [$this->line('a', '10', 1, 60), $this->line('b', '20', 1, 40)]
        );

        $this->assertSame(['a' => 12.0, 'b' => 8.0], $result);
    }

    public function testPercentageDiscountsEveryItemOfTheAdd(): void
    {
        $result = $this->amounts(
            $this->config(['t' => 'p', 'v' => 10, 'n' => 'Kit', 'i' => ['10' => 1, '20' => 1]]),
            [$this->line('a', '10', 2, 30), $this->line('b', '20', 1, 20)]
        );

        $this->assertSame(['a' => 6.0, 'b' => 2.0], $result);
    }

    public function testPercentageGivesNothingWhenAComponentWasRemoved(): void
    {
        $this->assertSame([], $this->amounts(
            $this->config(['t' => 'p', 'v' => 10, 'i' => ['10' => 1, '20' => 1]]),
            [$this->line('a', '10', 2, 30)]
        ));
    }

    public function testPercentageNeedsTheConfiguredProductsNotJustAsManyProducts(): void
    {
        // The storefront writes the tags, so another product tagged with the bundle is no component.
        $config = $this->config(['t' => 'p', 'v' => 10, 'i' => ['10' => 1, '20' => 1]]);

        $this->assertSame([], $this->amounts($config, [$this->line('a', '10', 1, 30), $this->line('b', '99', 1, 500)]));
        $this->assertSame(
            ['a' => 3.0, 'b' => 2.0],
            $this->amounts(
                $config,
                [$this->line('a', '10', 1, 30), $this->line('b', '20', 1, 20), $this->line('c', '99', 1, 500)]
            )
        );
    }

    public function testPercentageIsClampedAndAZeroPercentGivesNothing(): void
    {
        $lines = [$this->line('a', '10', 1, 30)];

        $this->assertSame(['a' => 30.0], $this->amounts($this->config(['t' => 'p', 'v' => 150]), $lines));
        $this->assertSame([], $this->amounts($this->config(['t' => 'p', 'v' => 0]), $lines));
    }

    public function testANamelessBundleGetsAGenericMessage(): void
    {
        $result = $this->bundles->calculate($this->config(['t' => 'p', 'v' => 10]), [$this->line('a', '10', 1, 30)]);

        $this->assertSame(['Bundle discount'], $result['a']['messages']);
    }

    public function testUntaggedItemsAndUnknownBundlesGetNothing(): void
    {
        $config = $this->config(['t' => 'p', 'v' => 10]);

        $this->assertSame([], $this->amounts($config, [
            $this->line('a', '10', 1, 30, null),
            $this->line('b', '10', 1, 30, 'i1', null),
            $this->line('c', '10', 1, 30, 'i2', 'unknown'),
        ]));
    }
}
