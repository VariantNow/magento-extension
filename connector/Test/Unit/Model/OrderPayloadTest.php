<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Variant\Connector\Api\Data\OrderInterface;
use Variant\Connector\Api\Data\OrderLineInterface;
use Variant\Connector\Model\OrderPayload;

class OrderPayloadTest extends TestCase
{
    /**
     * A page as Magento's REST layer sees it: the data objects' fields, nested.
     *
     * @param array[] $orders
     * @param array[] $items
     * @param int[] $firstOrderIds
     * @param int $pageSize
     * @return array
     */
    private function page(array $orders, array $items = [], array $firstOrderIds = [], int $pageSize = 100): array
    {
        return (new OrderPayload())->build($orders, $items, $firstOrderIds, $pageSize)->__toArray();
    }

    /**
     * A sales_order row.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return $overrides + [
            'entity_id' => '8', 'increment_id' => '000000008', 'store_id' => '1',
            'state' => 'new', 'status' => 'pending',
            'created_at' => '2026-11-09 14:41:02', 'updated_at' => '2026-11-09 14:41:03',
            'customer_id' => null, 'order_currency_code' => 'USD', 'grand_total' => '39.0000',
            'subtotal' => '34.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000',
            'shipping_amount' => '5.0000', 'total_refunded' => null, 'base_currency_code' => 'USD',
            'base_grand_total' => '39.0000', 'base_total_refunded' => null,
            'variant_user_id' => 'user_a1', 'variant_session_id' => 's1',
        ];
    }

    /**
     * A sales_order_item row.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return $overrides + [
            'item_id' => '20', 'order_id' => '8', 'parent_item_id' => null, 'product_id' => '1',
            'product_type' => 'simple', 'sku' => '24-MB01', 'qty_ordered' => '1.0000',
            'qty_refunded' => '0.0000', 'price' => '34.0000', 'row_total' => '34.0000',
            'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'amount_refunded' => '0.0000',
            'product_options' => null,
        ];
    }

    public function testAnOrderCarriesItsShopperTotalsAndLines(): void
    {
        $result = $this->page([$this->order()], [$this->item()]);

        $order = $result['orders'][0];
        $this->assertSame(8, $order['id']);
        $this->assertSame('000000008', $order['increment_id']);
        $this->assertSame('user_a1', $order['variant_user_id']);
        $this->assertSame('s1', $order['variant_session_id']);
        $this->assertSame('USD', $order['currency']);
        $this->assertSame('39.0000', $order['grand_total']);
        $this->assertNull($order['is_first_order']);
        $this->assertSame(
            [[
                'product_id' => 1, 'variant_product_id' => 1, 'part_product_ids' => [], 'sku' => '24-MB01',
                'product_type' => 'simple', 'qty_ordered' => '1.0000', 'qty_refunded' => '0.0000',
                'price' => '34.0000', 'row_total' => '34.0000', 'discount_amount' => '0.0000',
                'tax_amount' => '0.0000', 'amount_refunded' => '0.0000',
            ]],
            $order['lines']
        );
        $this->assertNull($result['next_updated_since']);
        $this->assertNull($result['next_after_id']);
    }

    public function testEveryFieldHasAGetterSoMagentoSerializesIt(): void
    {
        $page = (new OrderPayload())->build([$this->order()], [$this->item()], [], 100);
        $order = $page->getOrders()[0];
        $line = $order->getLines()[0];

        $this->assertInstanceOf(OrderInterface::class, $order);
        $this->assertInstanceOf(OrderLineInterface::class, $line);
        foreach ([[$order, OrderInterface::class], [$line, OrderLineInterface::class]] as [$object, $interface]) {
            $fields = array_values((new \ReflectionClass($interface))->getConstants());
            $this->assertEqualsCanonicalizing($fields, array_keys($object->__toArray()), $interface);
        }
        $this->assertSame('s1', $order->getVariantSessionId());
        $this->assertSame(1, $line->getVariantProductId());
    }

    public function testNoPersonalDataIsReturned(): void
    {
        $order = $this->page(
            [$this->order(['customer_email' => 'a@example.com', 'customer_firstname' => 'A'])]
        )['orders'][0];

        $this->assertArrayNotHasKey('customer_email', $order);
        $this->assertStringNotContainsString('example.com', json_encode($order));
    }

    public function testAConfigurableLineNamesTheParentAndTheSimpleItWasBoughtAs(): void
    {
        $lines = $this->page(
            [$this->order()],
            [
                $this->item(
                    ['item_id' => '30', 'product_id' => '1300', 'product_type' => 'configurable', 'sku' => 'MH01']
                ),
                $this->item(
                    ['item_id' => '31', 'parent_item_id' => '30', 'product_id' => '1290', 'sku' => 'MH01-XS-Black']
                ),
            ]
        )['orders'][0]['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame(1300, $lines[0]['product_id']);
        $this->assertSame(1290, $lines[0]['variant_product_id']);
        $this->assertSame('configurable', $lines[0]['product_type']);
    }

    public function testAFixedPriceBundleKeepsItsOwnAmountsAndListsItsParts(): void
    {
        $lines = $this->page(
            [$this->order()],
            [
                $this->item([
                    'item_id' => '40', 'product_id' => '45', 'product_type' => 'bundle', 'price' => '60.0000',
                    'row_total' => '60.0000', 'product_options' => '{"product_calculations":1}',
                ]),
                $this->item(
                    ['item_id' => '41', 'parent_item_id' => '40', 'product_id' => '46', 'row_total' => '0.0000']
                ),
                $this->item(
                    ['item_id' => '42', 'parent_item_id' => '40', 'product_id' => '47', 'row_total' => '0.0000']
                ),
            ]
        )['orders'][0]['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame(45, $lines[0]['variant_product_id']);
        $this->assertSame([46, 47], $lines[0]['part_product_ids']);
        $this->assertSame('60.0000', $lines[0]['price']);
        $this->assertSame('60.0000', $lines[0]['row_total']);
    }

    public function testADynamicBundlesAmountsAreItsPartsSums(): void
    {
        // Magento prices a dynamic bundle from its parts: the parent row carries zeros.
        $zero = ['price' => '0.0000', 'row_total' => '0.0000', 'discount_amount' => '0.0000'];
        $lines = $this->page(
            [$this->order()],
            [
                $this->item(
                    ['item_id' => '50', 'product_id' => '45', 'product_type' => 'bundle', 'qty_ordered' => '2.0000',
                        'product_options' => '{"product_calculations":0,"shipment_type":0}'] + $zero
                ),
                $this->item([
                    'item_id' => '51', 'parent_item_id' => '50', 'product_id' => '46', 'qty_ordered' => '2.0000',
                    'price' => '32.0000', 'row_total' => '64.0000', 'discount_amount' => '6.4000',
                    'tax_amount' => '5.2800', 'amount_refunded' => '32.0000',
                ]),
                $this->item([
                    'item_id' => '52', 'parent_item_id' => '50', 'product_id' => '47', 'qty_ordered' => '2.0000',
                    'price' => '14.0000', 'row_total' => '28.0000', 'discount_amount' => '2.8000',
                    'tax_amount' => '2.3100', 'amount_refunded' => null,
                ]),
            ]
        )['orders'][0]['lines'];

        $this->assertCount(1, $lines);
        $this->assertSame([46, 47], $lines[0]['part_product_ids']);
        $this->assertSame('92.0000', $lines[0]['row_total']);
        $this->assertSame('46.0000', $lines[0]['price'], 'per bundle: 92 for 2');
        $this->assertSame('9.2000', $lines[0]['discount_amount']);
        $this->assertSame('7.5900', $lines[0]['tax_amount']);
        $this->assertSame('32.0000', $lines[0]['amount_refunded']);
    }

    public function testUnreadableOptionsMeanTheBundlePricesItself(): void
    {
        $line = $this->page(
            [$this->order()],
            [
                $this->item(['item_id' => '60', 'product_type' => 'bundle', 'product_options' => 'a:1:{}']),
                $this->item(
                    ['item_id' => '61', 'parent_item_id' => '60', 'product_id' => '46', 'row_total' => '9.0000']
                ),
            ]
        )['orders'][0]['lines'][0];

        $this->assertSame('34.0000', $line['row_total']);
    }

    public function testFirstOrderIsKnownForSignedInCustomers(): void
    {
        $orders = $this->page(
            [
                $this->order(['entity_id' => '5', 'customer_id' => '7']),
                $this->order(['entity_id' => '9', 'customer_id' => '7']),
            ],
            [],
            [7 => 5]
        )['orders'];

        $this->assertTrue($orders[0]['is_first_order']);
        $this->assertFalse($orders[1]['is_first_order']);
    }

    public function testAFullPagePointsAtTheNext(): void
    {
        $result = $this->page(
            [
                $this->order(['entity_id' => '8', 'updated_at' => '2026-11-09 14:41:03']),
                $this->order(['entity_id' => '9', 'updated_at' => '2026-11-09 14:41:03']),
            ],
            [],
            [],
            2
        );

        $this->assertSame('2026-11-09 14:41:03', $result['next_updated_since']);
        $this->assertSame(9, $result['next_after_id']);
    }

    public function testAnEmptyPageHasNoNext(): void
    {
        $this->assertSame(
            ['orders' => [], 'next_updated_since' => null, 'next_after_id' => null],
            $this->page([])
        );
    }
}
