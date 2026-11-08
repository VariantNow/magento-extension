<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Test\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Variant\Pricing\Plugin\KeepDiscountDescription;

class KeepDiscountDescriptionTest extends TestCase
{
    public function testAppendsOfferNamesTheDescriptionLacks(): void
    {
        $this->assertSame(
            'Summer sale, 3-pack: 30% off',
            KeepDiscountDescription::withMessages('Summer sale', ['3-pack: 30% off'])
        );
        $this->assertSame('3-pack: 30% off', KeepDiscountDescription::withMessages('', ['3-pack: 30% off']));
    }

    public function testNeverRepeatsAnOfferNameThatHasACommaInIt(): void
    {
        $once = KeepDiscountDescription::withMessages('', ['Buy more, save more']);

        $this->assertSame('Buy more, save more', $once);
        $this->assertSame('Buy more, save more', KeepDiscountDescription::withMessages($once, ['Buy more, save more']));
    }
}
