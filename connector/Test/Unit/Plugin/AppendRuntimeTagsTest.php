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
use Variant\Connector\Model\PageContext;
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

    /**
     * @var PageContext|\PHPUnit\Framework\MockObject\MockObject
     */
    private $pageContext;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES)
        );
        $escaper->method('escapeJs')->willReturnCallback(static fn ($value) => (string)$value);
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES)
        );
        $secureRenderer = $this->createMock(SecureHtmlRenderer::class);
        $secureRenderer->method('renderTag')->willReturnCallback(
            static fn ($tag, $attributes, $content) => '<' . $tag
                . implode('', array_map(static fn ($name) => ' ' . $name, array_keys($attributes)))
                . '>' . $content . '</' . $tag . '>'
        );
        $this->renderer = $this->createMock(Renderer::class);
        $this->pageContext = $this->createMock(PageContext::class);
        $this->plugin = new AppendRuntimeTags($this->config, $escaper, $secureRenderer, $this->pageContext);
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
            . '<script data-variant-runtime src="https://edge.variantnow.com/sdk/shops/'
            . '123e4567-e89b-12d3-a456-426614174000.js"></script>'
            . "\n"
            . '<script data-variant-runtime src="https://edge.variantnow.com/sdk/v1/variant.js"></script>' . "\n",
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
            '<script data-variant-runtime src="http://localhost:3610/sdk/shops/a.js"></script>' . "\n"
            . '<script data-variant-runtime>'
            . '(function(h){var w=window,c=w.__VARIANT_CONFIG;if(c){c.hosts=h;return}'
            . 'Object.defineProperty(w,"__VARIANT_CONFIG",{configurable:true,get:function(){return c},'
            . 'set:function(v){c=v;if(v)v.hosts=h}})})({sdk:"http://localhost:3610"});'
            . '</script>' . "\n"
            . '<script data-variant-runtime src="http://localhost:3610/variant.js"></script>' . "\n",
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

    public function testAsyncShopsGetBothTagsAsync(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAsync')->willReturn(true);
        $this->config->method('getShopConfigUrl')->willReturn('https://edge.variantnow.com/sdk/shops/a.js');
        $this->config->method('getEngineUrl')->willReturn('https://edge.variantnow.com/sdk/v1/variant.js');
        $this->pageContext->method('attributes')->willReturn(['data-variant-page-type' => 'home']);

        $this->assertSame(
            '<script data-variant-runtime data-variant-page-type="home" async fetchpriority="high"'
            . ' src="https://edge.variantnow.com/sdk/shops/a.js"></script>' . "\n"
            . '<script data-variant-runtime async fetchpriority="high"'
            . ' src="https://edge.variantnow.com/sdk/v1/variant.js"></script>' . "\n",
            $this->plugin->afterRenderHeadContent($this->renderer, '')
        );
    }

    public function testDevHostsReachAnAsyncConfigThatArrivesLater(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isAsync')->willReturn(true);
        $this->config->method('getShopConfigUrl')->willReturn('http://localhost:3610/sdk/shops/a.js');
        $this->config->method('getEngineUrl')->willReturn('http://localhost:3610/variant.js');
        $this->config->method('getDevSdkHost')->willReturn('http://localhost:3610');

        $html = $this->plugin->afterRenderHeadContent($this->renderer, '');

        // Set as the config assigns itself, not only when it is already there.
        $this->assertStringContainsString('Object.defineProperty(w,"__VARIANT_CONFIG"', $html);
        $this->assertSame(2, substr_count($html, ' async fetchpriority="high"'));
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

    public function testLabelsTheConfigTagWithThePage(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getShopConfigUrl')->willReturn('https://edge.variantnow.com/sdk/shops/a.js');
        $this->config->method('getEngineUrl')->willReturn('https://edge.variantnow.com/sdk/v1/variant.js');
        $this->pageContext->method('attributes')->willReturn([
            'data-variant-page-type' => 'product',
            'data-variant-page-handle' => 'tee "x"',
            'data-variant-product-collections' => 'women,tops-women',
        ]);

        $html = $this->plugin->afterRenderHeadContent($this->renderer, '');

        $this->assertStringStartsWith(
            '<script data-variant-runtime data-variant-page-type="product"'
            . ' data-variant-page-handle="tee &quot;x&quot;"'
            . ' data-variant-product-collections="women,tops-women"'
            . ' src="https://edge.variantnow.com/sdk/shops/a.js"></script>',
            $html
        );
        // Only the config tag carries the page.
        $this->assertSame(1, substr_count($html, 'data-variant-page-type'));
    }
}
