<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\Config\Backend\FilesHost;
use Variant\Connector\Model\Config\Backend\ShopId;

class ValidationTest extends TestCase
{
    /**
     * Run a backend model's beforeSave on a value and return what it would store.
     *
     * @param string $class
     * @param string $value
     * @return string
     */
    private function save(string $class, string $value): string
    {
        $model = (new ObjectManager($this))->getObject($class);
        $model->setValue($value);
        $model->beforeSave();

        return (string)$model->getValue();
    }

    public function testShopIdIsTrimmedAndLowercased(): void
    {
        $this->assertSame(
            '123e4567-e89b-12d3-a456-426614174000',
            $this->save(ShopId::class, ' 123E4567-E89B-12D3-A456-426614174000 ')
        );
    }

    public function testShopIdMayBeEmpty(): void
    {
        $this->assertSame('', $this->save(ShopId::class, ''));
    }

    /**
     * @dataProvider notShopIds
     * @param string $value
     */
    public function testShopIdRejectsAnythingButAUuid(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->save(ShopId::class, $value);
    }

    /**
     * @return array
     */
    public static function notShopIds(): array
    {
        return [
            'sdk key' => ['sdk-9oZrzNbOrX6BSzl'],
            'url' => ['https://edge.variantnow.com/sdk/shops/123e4567-e89b-12d3-a456-426614174000.js'],
            'markup' => ['123e4567-e89b-12d3-a456-426614174000"><script>'],
        ];
    }

    /**
     * @dataProvider filesHosts
     * @param string $value
     * @param string $stored
     */
    public function testFilesHostAcceptsOrigins(string $value, string $stored): void
    {
        $this->assertSame($stored, $this->save(FilesHost::class, $value));
    }

    /**
     * @return array
     */
    public static function filesHosts(): array
    {
        return [
            'production' => ['https://edge.variantnow.com', 'https://edge.variantnow.com'],
            'trailing slash' => ['https://cdn.variantnow.com/', 'https://cdn.variantnow.com'],
            'local dev' => ['http://localhost:3010', 'http://localhost:3010'],
            'dev slot' => ['https://wt1.dev.variantnow.com', 'https://wt1.dev.variantnow.com'],
        ];
    }

    /**
     * @dataProvider notFilesHosts
     * @param string $value
     */
    public function testFilesHostRejectsAnythingElse(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->save(FilesHost::class, $value);
    }

    /**
     * @return array
     */
    public static function notFilesHosts(): array
    {
        return [
            'plain http' => ['http://edge.variantnow.com'],
            'with a path' => ['https://edge.variantnow.com/sdk/v1'],
            'no scheme' => ['edge.variantnow.com'],
            'empty' => [''],
        ];
    }
}
