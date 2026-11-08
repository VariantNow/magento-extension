<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\Config;

class ConfigTest extends TestCase
{
    private const SHOP_ID = '123e4567-e89b-12d3-a456-426614174000';

    /**
     * Build a Config over fixed settings.
     *
     * @param array $values
     * @param bool $enabled
     * @return Config
     */
    private function config(array $values, bool $enabled = true): Config
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->with(Config::XML_PATH_ENABLED)->willReturn($enabled);
        $scopeConfig->method('getValue')->willReturnCallback(static fn ($path) => $values[$path] ?? null);

        return new Config($scopeConfig);
    }

    public function testBuildsBothUrlsFromTheFilesHost(): void
    {
        $config = $this->config([
            Config::XML_PATH_SHOP_ID => self::SHOP_ID,
            Config::XML_PATH_FILES_HOST => 'https://edge.variantnow.com/',
        ]);

        $this->assertSame(
            'https://edge.variantnow.com/sdk/shops/' . self::SHOP_ID . '.js',
            $config->getShopConfigUrl()
        );
        $this->assertSame('https://edge.variantnow.com/sdk/v1/variant.js', $config->getEngineUrl());
    }

    public function testProductionCdnsAreNotADevelopmentSdk(): void
    {
        foreach (['https://edge.variantnow.com', 'https://cdn.variantnow.com/'] as $host) {
            $config = $this->config([
                Config::XML_PATH_SHOP_ID => self::SHOP_ID,
                Config::XML_PATH_FILES_HOST => $host,
            ]);
            $this->assertNull($config->getDevSdkHost());
        }
    }

    public function testADevelopmentSdkServesTheEngineAtItsRoot(): void
    {
        $config = $this->config([
            Config::XML_PATH_SHOP_ID => self::SHOP_ID,
            Config::XML_PATH_FILES_HOST => 'http://localhost:3610/',
        ]);

        $this->assertSame('http://localhost:3610', $config->getDevSdkHost());
        $this->assertSame('http://localhost:3610/variant.js', $config->getEngineUrl());
        $this->assertSame(
            'http://localhost:3610/sdk/shops/' . self::SHOP_ID . '.js',
            $config->getShopConfigUrl()
        );
    }

    public function testIsDisabledWithoutAShopId(): void
    {
        $config = $this->config([Config::XML_PATH_SHOP_ID => '  ']);

        $this->assertFalse($config->isEnabled());
    }

    public function testIsDisabledWhenSwitchedOff(): void
    {
        $config = $this->config([Config::XML_PATH_SHOP_ID => self::SHOP_ID], false);

        $this->assertFalse($config->isEnabled());
    }

    public function testIsEnabledWithAShopId(): void
    {
        $config = $this->config([Config::XML_PATH_SHOP_ID => ' ' . self::SHOP_ID . ' ']);

        $this->assertTrue($config->isEnabled());
        $this->assertSame(self::SHOP_ID, $config->getShopId());
    }

    public function testOnlyAsyncIsAsync(): void
    {
        $this->assertTrue($this->config([Config::XML_PATH_LOADING_MODE => 'async'])->isAsync());
        $this->assertFalse($this->config([Config::XML_PATH_LOADING_MODE => 'blocking'])->isAsync());
        $this->assertFalse($this->config([Config::XML_PATH_LOADING_MODE => 'turbo'])->isAsync());
        $this->assertFalse($this->config([])->isAsync());
    }
}
