<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\ConsentBridge;

class ConsentBridgeTest extends TestCase
{
    /**
     * A ConsentBridge whose module lives at `$dir`.
     *
     * @param string|null $dir
     * @return ConsentBridge
     */
    private function bridge(?string $dir): ConsentBridge
    {
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->method('getPath')->willReturn($dir);

        return new ConsentBridge($registrar, new File());
    }

    public function testInlinesTheShippedScriptWithoutItsDocComment(): void
    {
        $script = $this->bridge(dirname(__DIR__, 3))->script();

        $this->assertStringStartsWith('(function (w, d) {', $script);
        $this->assertStringEndsWith('})(window, document);', $script);
        $this->assertStringNotContainsString('Copyright', $script);
        // Nothing in it may close the inline tag early.
        $this->assertStringNotContainsString('</script', $script);
    }

    public function testRendersNothingWhenTheFileIsMissing(): void
    {
        $this->assertSame('', $this->bridge('/nonexistent/module')->script());
    }

    public function testRendersNothingWhenTheModuleIsUnknown(): void
    {
        $this->assertSame('', $this->bridge(null)->script());
    }
}
