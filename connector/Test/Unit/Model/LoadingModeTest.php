<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\Config;
use Variant\Connector\Model\LoadingMode;

class LoadingModeTest extends TestCase
{
    /**
     * @var WriterInterface|MockObject
     */
    private $writer;

    /**
     * @var TypeListInterface|MockObject
     */
    private $cacheTypes;

    /**
     * @var ManagerInterface|MockObject
     */
    private $events;

    /**
     * Build a LoadingMode over a store holding `$stored`.
     *
     * @param string|null $stored
     * @return LoadingMode
     */
    private function loadingMode(?string $stored): LoadingMode
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with(Config::XML_PATH_LOADING_MODE)->willReturn($stored);
        $this->writer = $this->createMock(WriterInterface::class);
        $this->cacheTypes = $this->createMock(TypeListInterface::class);
        $this->events = $this->createMock(ManagerInterface::class);

        return new LoadingMode(
            $this->writer,
            $scopeConfig,
            $this->createMock(ReinitableConfigInterface::class),
            $this->cacheTypes,
            $this->events
        );
    }

    public function testAChangeIsSavedAndRefreshesTheCachedPages(): void
    {
        $mode = $this->loadingMode('blocking');
        $this->writer->expects($this->once())->method('save')->with(Config::XML_PATH_LOADING_MODE, 'async');
        $cleaned = [];
        $this->cacheTypes->method('cleanType')->willReturnCallback(
            static function ($type) use (&$cleaned) {
                $cleaned[] = $type;
            }
        );
        $refreshed = [];
        $this->events->method('dispatch')->willReturnCallback(
            static function ($name, $data) use (&$refreshed) {
                $refreshed[] = $name . ':' . $data['type'];
            }
        );

        $this->assertTrue($mode->save('async'));
        $this->assertSame(['config', 'full_page'], $cleaned);
        // The event System > Cache Management fires, on which Varnish purges.
        $this->assertSame(
            ['adminhtml_cache_refresh_type:config', 'adminhtml_cache_refresh_type:full_page'],
            $refreshed
        );
    }

    public function testTheSameModeChangesNothing(): void
    {
        $mode = $this->loadingMode('async');
        $this->writer->expects($this->never())->method('save');
        $this->cacheTypes->expects($this->never())->method('cleanType');

        $this->assertTrue($mode->save('async'));
    }

    public function testAMissingModeIsBlocking(): void
    {
        $mode = $this->loadingMode(null);
        $this->writer->expects($this->never())->method('save');

        $this->assertSame('blocking', $mode->get());
        $this->assertTrue($mode->save('blocking'));
    }

    public function testRejectsAnUnknownMode(): void
    {
        $mode = $this->loadingMode('blocking');
        $this->writer->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $mode->save('turbo');
    }
}
