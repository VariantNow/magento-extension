<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Test\Unit\Model\PriceTest;

use PHPUnit\Framework\TestCase;
use Variant\Pricing\Model\PriceTest\Resolver;

/**
 * Mirrors the Shopify price-overrider Cart Transform (price-overrider/src/cart_transform_run.rs).
 */
class ResolverTest extends TestCase
{
    /**
     * @var Resolver
     */
    private $resolver;

    protected function setUp(): void
    {
        $this->resolver = new Resolver();
    }

    /**
     * @param array $rules
     * @param bool $on
     * @return array
     */
    private function arm(array $rules, bool $on = true): array
    {
        return ['on' => $on, 'r' => $rules];
    }

    /**
     * @param string[] $keys
     * @param array $config
     * @return string[]
     */
    private function keysOf(array $keys, array $config): array
    {
        return array_values(array_unique(array_column($this->resolver->liveRules($keys, $config), 'key')));
    }

    public function testKeepsOnlyTheLastArmOfAnExperiment(): void
    {
        $config = [
            'EXP1A:control' => $this->arm([['p' => '6', 'v' => '1']]),
            'EXP1A:v' => $this->arm([['p' => '6', 'v' => '2']]),
        ];

        $this->assertSame(['EXP1A:v'], $this->keysOf(['EXP1A:control', 'EXP1A:v'], $config));
    }

    public function testKeepsEveryExperimentWhenSeveralAreActive(): void
    {
        $config = [
            'EXP1A:a' => $this->arm([['p' => '1', 'v' => '1']]),
            'EXP2B:x' => $this->arm([['p' => '2', 'v' => '1']]),
            'EXP1A:b' => $this->arm([['p' => '1', 'v' => '2']]),
        ];

        $this->assertSame(['EXP2B:x', 'EXP1A:b'], $this->keysOf(['EXP1A:a', 'EXP2B:x', 'EXP1A:b'], $config));
    }

    public function testSkipsAStaleLastKeySoTheLiveArmStillApplies(): void
    {
        $config = ['EXP1A:a' => $this->arm([['p' => '1', 'v' => '1']]), 'EXP1A:b' => $this->arm([], false)];

        $this->assertSame(['EXP1A:a'], $this->keysOf(['EXP1A:a', 'EXP1A:b'], $config));
    }

    public function testIgnoresKeysWithNoPublishedArm(): void
    {
        $this->assertSame([], $this->keysOf(['EXP9Z:a', 'garbage'], []));
    }

    public function testAProductRulePricesEveryVariant(): void
    {
        $rules = $this->resolver->liveRules(
            ['EXP1A:v'],
            ['EXP1A:v' => $this->arm([['p' => '62', 't' => 'f', 'v' => '44.5']])]
        );

        $this->assertSame(
            ['key' => 'EXP1A:v', 't' => 'f', 'v' => 44.5],
            $this->resolver->resolve($rules, '62', '53', 'M / Black')
        );
        $this->assertNull($this->resolver->resolve($rules, '6', '6', 'Default Title'));
    }

    public function testAPerVariantRulePricesOnlyTheVariantsItLists(): void
    {
        $rules = $this->resolver->liveRules(['EXP1A:v'], ['EXP1A:v' => $this->arm([
            ['p' => '62', 'vr' => ['53' => ['t' => 'f', 'v' => '40'], '54' => ['t' => 'p', 'v' => '-10']]],
        ])]);

        $this->assertSame(40.0, $this->resolver->resolve($rules, '62', '53', 'M / Black')['v']);
        $this->assertSame('p', $this->resolver->resolve($rules, '62', '54', 'M / Gray')['t']);
        $this->assertNull($this->resolver->resolve($rules, '62', '47', 'XS / Black'));
    }

    public function testAnOptionRulePricesByTheValueOfOneOption(): void
    {
        $rules = $this->resolver->liveRules(['EXP1A:v'], ['EXP1A:v' => $this->arm([
            ['p' => '62', 't' => 'f', 'd' => 1, 'o' => ['Black' => '45', 'Gray' => '48']],
        ])]);

        $this->assertSame(45.0, $this->resolver->resolve($rules, '62', '53', 'M / Black')['v']);
        $this->assertSame(48.0, $this->resolver->resolve($rules, '62', '54', 'M / Gray')['v']);
        $this->assertNull($this->resolver->resolve($rules, '62', '55', 'M / Orange'));
    }

    public function testComputesFixedPercentageAndAmountPrices(): void
    {
        $this->assertSame(44.5, $this->resolver->price(52.0, ['t' => 'f', 'v' => 44.5]));
        $this->assertSame(46.8, $this->resolver->price(52.0, ['t' => 'p', 'v' => -10.0]));
        $this->assertSame(57.2, $this->resolver->price(52.0, ['t' => 'p', 'v' => 10.0]));
        $this->assertSame(47.0, $this->resolver->price(52.0, ['t' => 'a', 'v' => -5.0]));
        $this->assertSame(0.0, $this->resolver->price(52.0, ['t' => 'a', 'v' => -80.0]));
    }
}
