<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Plugin;

use Magento\Framework\App\Response\Http;
use Magento\Framework\View\Result\Layout;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Plugin\KeepRuntimeTagsInHead;

class KeepRuntimeTagsInHeadTest extends TestCase
{
    private const CONFIG = '<script data-variant-runtime src="https://edge.variantnow.com/sdk/shops/a.js"></script>';
    private const ENGINE = '<script data-variant-runtime src="https://edge.variantnow.com/sdk/v1/variant.js"></script>';

    /**
     * @param string $html
     * @return string the response content after the plugin
     */
    private function render(string $html): string
    {
        $response = new class extends Http {
            /** @var string */
            public $html = '';

            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- no parent wiring in a unit test
            public function __construct()
            {
            }

            /**
             * @inheritdoc
             */
            public function getContent()
            {
                return $this->html;
            }

            /**
             * @inheritdoc
             */
            public function setContent($value)
            {
                $this->html = $value;
                return $this;
            }
        };
        $response->html = $html;
        $layout = $this->createMock(Layout::class);
        (new KeepRuntimeTagsInHead())->afterRenderResult($layout, $layout, $response);
        return $response->html;
    }

    public function testMovesTagsMagentoMovedToTheBodyBackAfterTheTitle(): void
    {
        $html = '<html><head><title>Shop</title><link rel="stylesheet" href="a.css"></head><body>Page'
            . '<script src="require.js"></script>' . self::CONFIG . "\n" . self::ENGINE . "\n</body></html>";

        $this->assertSame(
            '<html><head><title>Shop</title>' . "\n" . self::CONFIG . "\n" . self::ENGINE . "\n"
            . '<link rel="stylesheet" href="a.css"></head><body>Page<script src="require.js"></script></body></html>',
            $this->render($html)
        );
    }

    public function testLeavesTagsAlreadyInTheHeadAlone(): void
    {
        $html = '<html><head><title>Shop</title>' . self::CONFIG . self::ENGINE . '</head><body>Page</body></html>';

        $this->assertSame($html, $this->render($html));
    }

    public function testLeavesPagesWithoutTheTagsAlone(): void
    {
        $html = '<html><head><title>Shop</title></head><body><script src="require.js"></script></body></html>';

        $this->assertSame($html, $this->render($html));
    }
}
