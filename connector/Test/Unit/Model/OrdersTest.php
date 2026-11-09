<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\OrderPayload;
use Variant\Connector\Model\Orders;

class OrdersTest extends TestCase
{
    /**
     * Orders over a database that must never be read.
     *
     * @return Orders
     */
    private function orders(): Orders
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        return new Orders($resource, new OrderPayload());
    }

    public function testSinceTakesATimeOrADate(): void
    {
        $this->assertSame('2026-11-09 14:00:00', $this->orders()->parseSince('2026-11-09 14:00:00'));
        $this->assertSame('2026-11-09 00:00:00', $this->orders()->parseSince(' 2026-11-09 '));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badSinceProvider(): array
    {
        return [
            'empty' => [''],
            'iso with zone' => ['2026-11-09T14:00:00Z'],
            'no such day' => ['2026-02-30 10:00:00'],
            'sql' => ["2026-11-09' OR 1=1 --"],
        ];
    }

    /**
     * @dataProvider badSinceProvider
     * @param string $value
     * @return void
     */
    public function testAnythingElseIsRefused(string $value): void
    {
        $this->expectException(InputException::class);
        $this->orders()->parseSince($value);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function badPageProvider(): array
    {
        return [
            'negative afterId' => [-1, 100],
            'empty page' => [0, 0],
            'page too large' => [0, Orders::MAX_PAGE_SIZE + 1],
        ];
    }

    /**
     * @dataProvider badPageProvider
     * @param int $afterId
     * @param int $pageSize
     * @return void
     */
    public function testABadPageIsRefusedBeforeTheDatabaseIsRead(int $afterId, int $pageSize): void
    {
        $orders = $this->orders();

        $this->expectException(InputException::class);
        $orders->getUpdatedSince('2026-11-09 00:00:00', $afterId, $pageSize);
    }
}
