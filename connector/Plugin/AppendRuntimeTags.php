<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Plugin;

use Magento\Framework\Escaper;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\View\Page\Config\Renderer;
use Variant\Connector\Model\Config;
use Variant\Connector\Model\ConsentBridge;
use Variant\Connector\Model\PageContext;

/**
 * Writes Variant's two runtime tags right after the page's meta and title, ahead of
 * RequireJS and every stylesheet, without depending on the theme's layout.
 *
 * By default both tags are parser-blocking (no async, no defer): the engine buckets the
 * visitor and applies redirect and DOM tests before first paint, which is what keeps the
 * control page from flashing. A shop whose "Script loading" is Async (Config::isAsync) gets
 * both tags async, so the page never waits for Variant: as on Shopify, whichever of the two
 * arrives first waits for the other (the config calls __VARIANT_BOOT, which the engine parks),
 * and the engine then loads the runner async too.
 *
 * Ahead of both, an inline script tells the runtime the visitor's analytics consent from the
 * store's consent tool (ConsentBridge); the shop's Cookie consent setting decides what it gates.
 *
 * Every tag carries `data-variant-runtime`, by which KeepRuntimeTagsInHead finds them again
 * when Magento's "Move JS code to the bottom of the page" has moved them. The config tag also
 * says what the page is (PageContext), which the engine passes on to experiment targeting.
 */
class AppendRuntimeTags
{
    public const MARKER = 'data-variant-runtime';

    /**
     * @param Config $config
     * @param Escaper $escaper
     * @param SecureHtmlRenderer $secureRenderer
     * @param PageContext $pageContext
     * @param ConsentBridge $consentBridge
     */
    public function __construct(
        private readonly Config $config,
        private readonly Escaper $escaper,
        private readonly SecureHtmlRenderer $secureRenderer,
        private readonly PageContext $pageContext,
        private readonly ConsentBridge $consentBridge
    ) {
    }

    /**
     * Append the shop config and engine tags to the head content.
     *
     * @param Renderer $subject
     * @param string $result
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterRenderHeadContent(Renderer $subject, $result): string
    {
        if (!$this->config->isEnabled()) {
            return (string)$result;
        }

        $loading = $this->config->isAsync() ? ' async fetchpriority="high"' : '';
        return $result
            . $this->consentTag()
            . '<script ' . self::MARKER . $this->pageAttributes() . $loading . ' src="'
            . $this->escaper->escapeUrl($this->config->getShopConfigUrl()) . '"></script>' . "\n"
            . $this->devHostsTag()
            . '<script ' . self::MARKER . $loading . ' src="'
            . $this->escaper->escapeUrl($this->config->getEngineUrl()) . '"></script>' . "\n";
    }

    /**
     * The consent bridge, inline with the CSP nonce, or nothing when it cannot be read.
     *
     * @return string
     */
    private function consentTag(): string
    {
        $script = $this->consentBridge->script();
        if ($script === '') {
            return '';
        }
        $attributes = [self::MARKER => '', 'data-onetrust-category' => $this->config->getOneTrustCategory()];
        return $this->secureRenderer->renderTag('script', $attributes, $script, false) . "\n";
    }

    /**
     * The page's type, product or category, and categories, as attributes of the config tag.
     *
     * @return string
     */
    private function pageAttributes(): string
    {
        $html = '';
        foreach ($this->pageContext->attributes() as $name => $value) {
            $html .= ' ' . $name . '="' . $this->escaper->escapeHtmlAttr($value) . '"';
        }
        return $html;
    }

    /**
     * On a development SDK, point the engine at it for its runner too; nothing on production.
     *
     * Runs between the two tags. A blocking config has already set window.__VARIANT_CONFIG; an
     * async one usually has not, so the hosts are added as the config assigns itself. Either
     * way the engine sees them when it starts.
     *
     * @return string
     */
    private function devHostsTag(): string
    {
        $host = $this->config->getDevSdkHost();
        if ($host === null) {
            return '';
        }
        $script = '(function(h){var w=window,c=w.__VARIANT_CONFIG;if(c){c.hosts=h;return}'
            . 'Object.defineProperty(w,"__VARIANT_CONFIG",{configurable:true,get:function(){return c},'
            . 'set:function(v){c=v;if(v)v.hosts=h}})})({sdk:"' . $this->escaper->escapeJs($host) . '"});';
        return $this->secureRenderer->renderTag('script', [self::MARKER => ''], $script, false) . "\n";
    }
}
