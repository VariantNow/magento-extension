<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Plugin;

use Magento\Framework\Escaper;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\View\Page\Config\Renderer;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\Config;
use Variant\Connector\Plugin\AppendRuntimeTags;

class AppendRuntimeTagsTest extends TestCase
{
    private const HEAD = '<meta charset="utf-8"/><title>Store</title>';

    /**
     * @var Config|\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var AppendRuntimeTags
     */
    private $plugin;

    /**
     * @var Renderer|\PHPUnit\Framework\MockObject\MockObject
     */
    private $renderer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES)
        );
        $escaper->method('escapeJs')->willReturnCallback(static fn ($value) => (string)$value);
        $secureRenderer = $this->createMock(SecureHtmlRenderer::class);
        $secureRenderer->method('renderTag')->willReturnCallback(
            static fn ($tag, $attributes, $content) => '<' . $tag . '>' . $content . '</' . $tag . '>'
        );
        $this->renderer = $this->createMock(Renderer::class);
        $this->plugin = new AppendRuntimeTags($this->config, $escaper, $secureRenderer);
    }

    public function testLeavesTheHeadAloneWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->assertSame(self::HEAD, $this->plugin->afterRenderHeadContent($this->renderer, self::HEAD));
    }

    public function testAppendsConfigThenEngineAfterTheExistingHead(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShopConfigUrl')
            ->willReturn('https://edge.variantnow.com/sdk/shops/123e4567-e89b-12d3-a456-426614174000.js');
        $this->config->method('getEngineUrl')->willReturn('https://edge.variantnow.com/sdk/v1/variant.js');

        $this->assertSame(
            self::HEAD
            . '<script src="https://edge.variantnow.com/sdk/shops/123e4567-e89b-12d3-a456-426614174000.js"></script>'
            . "\n"
            . '<script src="https://edge.variantnow.com/sdk/v1/variant.js"></script>' . "\n",
            $this->plugin->afterRenderHeadContent($this->renderer, self::HEAD)
        );
    }

    public function testPointsTheEngineAtADevelopmentSdkBetweenTheTags(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShopConfigUrl')->willReturn('http://localhost:3610/sdk/shops/a.js');
        $this->config->method('getEngineUrl')->willReturn('http://localhost:3610/variant.js');
        $this->config->method('getDevSdkHost')->willReturn('http://localhost:3610');

        $this->assertSame(
            '<script src="http://localhost:3610/sdk/shops/a.js"></script>' . "\n"
            . '<script>window.__VARIANT_CONFIG&&(window.__VARIANT_CONFIG.hosts={sdk:"http://localhost:3610"});'
            . '</script>' . "\n"
            . '<script src="http://localhost:3610/variant.js"></script>' . "\n",
            $this->plugin->afterRenderHeadContent($this->renderer, '')
        );
    }

    public function testTagsAreParserBlocking(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShopConfigUrl')->willReturn('https://edge.variantnow.com/sdk/shops/a.js');
        $this->config->method('getEngineUrl')->willReturn('https://edge.variantnow.com/sdk/v1/variant.js');

        $html = $this->plugin->afterRenderHeadContent($this->renderer, '');

        $this->assertStringNotContainsString('async', $html);
        $this->assertStringNotContainsString('defer', $html);
    }

    public function testEscapesTheUrlsIntoTheAttribute(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShopConfigUrl')->willReturn('https://edge.variantnow.com/sdk/shops/"x".js');
        $this->config->method('getEngineUrl')->willReturn('https://edge.variantnow.com/sdk/v1/variant.js');

        $this->assertStringContainsString(
            'src="https://edge.variantnow.com/sdk/shops/&quot;x&quot;.js"',
            $this->plugin->afterRenderHeadContent($this->renderer, '')
        );
    }
}
