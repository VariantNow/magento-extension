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
use Variant\Connector\Model\PageContext;

/**
 * Writes Variant's two runtime tags right after the page's meta and title, ahead of
 * RequireJS and every stylesheet, without depending on the theme's layout.
 *
 * Both tags are parser-blocking on purpose (no async, no defer): the engine buckets
 * the visitor and applies redirect and DOM tests before first paint, which is what
 * keeps the control page from flashing. See storefront-sdk/README-bundle.md.
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
     */
    public function __construct(
        private readonly Config $config,
        private readonly Escaper $escaper,
        private readonly SecureHtmlRenderer $secureRenderer,
        private readonly PageContext $pageContext
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

        return $result
            . '<script ' . self::MARKER . $this->pageAttributes() . ' src="'
            . $this->escaper->escapeUrl($this->config->getShopConfigUrl()) . '"></script>' . "\n"
            . $this->devHostsTag()
            . '<script ' . self::MARKER . ' src="'
            . $this->escaper->escapeUrl($this->config->getEngineUrl()) . '"></script>' . "\n";
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
     * Runs between the two tags: the config has just set window.__VARIANT_CONFIG, and the engine
     * reads its hosts when it starts.
     *
     * @return string
     */
    private function devHostsTag(): string
    {
        $host = $this->config->getDevSdkHost();
        if ($host === null) {
            return '';
        }
        $script = 'window.__VARIANT_CONFIG&&(window.__VARIANT_CONFIG.hosts={sdk:"'
            . $this->escaper->escapeJs($host) . '"});';
        return $this->secureRenderer->renderTag('script', [self::MARKER => ''], $script, false) . "\n";
    }
}
