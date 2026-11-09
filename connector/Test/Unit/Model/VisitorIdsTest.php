<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\VisitorIds;

class VisitorIdsTest extends TestCase
{
    public function testTheStorefrontsIdsAreKept(): void
    {
        $ids = new VisitorIds();
        $this->assertSame(
            'user_3f2b9c1e-7a4d-4e8b-9c0d-1a2b3c4d5e6f',
            $ids->parse('user_3f2b9c1e-7a4d-4e8b-9c0d-1a2b3c4d5e6f')
        );
        $this->assertSame(
            '3f2b9c1e-7a4d-4e8b-9c0d-1a2b3c4d5e6f',
            $ids->parse('3f2b9c1e-7a4d-4e8b-9c0d-1a2b3c4d5e6f')
        );
    }

    public function testAnEncodedValueIsDecoded(): void
    {
        $this->assertSame('user_abc', (new VisitorIds())->parse(rawurlencode(' user_abc ')));
    }

    public function testAnythingElseIsNoId(): void
    {
        $ids = new VisitorIds();
        $this->assertNull($ids->parse(null));
        $this->assertNull($ids->parse(''));
        $this->assertNull($ids->parse('user abc'));
        $this->assertNull($ids->parse('<script>'));
        $this->assertNull($ids->parse(str_repeat('a', 65)));
    }

    public function testOnlyTheBridgesExactValueAllowsAttribution(): void
    {
        $ids = new VisitorIds();
        $this->assertTrue($ids->attributionAllowed('1'));
        $this->assertFalse($ids->attributionAllowed(null));
        $this->assertFalse($ids->attributionAllowed(''));
        $this->assertFalse($ids->attributionAllowed('true'));
    }
}
