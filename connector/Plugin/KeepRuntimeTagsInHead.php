<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Plugin;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\Layout;

/**
 * Puts Variant's runtime tags back in the head after Magento's "Move JS code to the bottom of
 * the page" (Advanced › Developer, `dev/js/move_script_to_bottom`) has moved every script to
 * the end of the body. Left there, the engine would bucket and apply redirect and DOM tests
 * only after first paint, flashing the control page — what the tags exist to prevent.
 *
 * Runs after Magento's own plugin (sortOrder above its -10), and touches only the tags marked
 * `data-variant-runtime`, keeping their order.
 */
class KeepRuntimeTagsInHead
{
    private const TAGS = '#<script\b[^>]*\b' . AppendRuntimeTags::MARKER . '\b[^>]*>.*?</script>\s*#s';

    /**
     * Move the tags back right after the title when they ended up past the head.
     *
     * @param Layout $subject
     * @param Layout $result
     * @param ResponseInterface $httpResponse
     * @return Layout
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterRenderResult(Layout $subject, Layout $result, ResponseInterface $httpResponse)
    {
        if (!method_exists($httpResponse, 'getContent')) {
            return $result;
        }
        $content = (string)$httpResponse->getContent();
        $headEnd = stripos($content, '</head>');
        if ($headEnd === false
            || !preg_match_all(self::TAGS, $content, $tags, PREG_OFFSET_CAPTURE)
            || $tags[0][0][1] < $headEnd) {
            return $result;
        }
        $moved = preg_replace(self::TAGS, '', $content);
        $html = implode('', array_map(fn ($tag) => $tag[0], $tags[0]));
        $titleEnd = stripos($moved, '</title>');
        $at = $titleEnd !== false ? $titleEnd + strlen('</title>') : stripos($moved, '</head>');
        $httpResponse->setContent(substr_replace($moved, "\n" . $html, $at, 0));
        return $result;
    }
}
