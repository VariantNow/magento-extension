<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

use Variant\Connector\Model\Data\Order;
use Variant\Connector\Model\Data\OrderLine;
use Variant\Connector\Model\Data\OrdersPage;

/**
 * Shapes rows of sales_order and sales_order_item into the orders feed (OrdersInterface), as
 * data objects Magento's REST layer serializes itself.
 *
 * Amounts stay the decimal strings MySQL returns, so nothing is rounded on the way. A line is a
 * top-level item: a configurable product's line names the parent product (as Variant's catalog
 * does) and the child simple product it was bought as.
 */
class OrderPayload
{
    /** Line amounts a dynamically priced bundle carries on its parts. */
    private const LINE_AMOUNTS = ['row_total', 'discount_amount', 'tax_amount', 'amount_refunded'];

    /**
     * The feed's response for one page.
     *
     * @param array[] $orders sales_order rows, oldest change first
     * @param array[] $items sales_order_item rows of those orders
     * @param int[] $firstOrderIds customer id => that customer's first order id
     * @param int $pageSize The page size asked for; a full page means there may be more
     * @return OrdersPage
     */
    public function build(array $orders, array $items, array $firstOrderIds, int $pageSize): OrdersPage
    {
        $linesByOrder = $this->lines($items);
        $payload = [];
        foreach ($orders as $row) {
            $id = (int)$row['entity_id'];
            $customerId = $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
            $payload[] = new Order([
                'id' => $id,
                'increment_id' => (string)$row['increment_id'],
                'store_id' => (int)$row['store_id'],
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'state' => $row['state'],
                'status' => $row['status'],
                'variant_user_id' => $row['variant_user_id'],
                'variant_session_id' => $row['variant_session_id'],
                'currency' => $row['order_currency_code'],
                'grand_total' => $row['grand_total'],
                'subtotal' => $row['subtotal'],
                'discount_amount' => $row['discount_amount'],
                'tax_amount' => $row['tax_amount'],
                'shipping_amount' => $row['shipping_amount'],
                'total_refunded' => $row['total_refunded'],
                'base_currency' => $row['base_currency_code'],
                'base_grand_total' => $row['base_grand_total'],
                'base_total_refunded' => $row['base_total_refunded'],
                // Known for signed-in customers only: a guest's earlier orders are not linked.
                'is_first_order' => $customerId !== null && isset($firstOrderIds[$customerId])
                    ? $firstOrderIds[$customerId] === $id
                    : null,
                'lines' => $linesByOrder[$id] ?? [],
            ]);
        }
        $last = end($orders);
        $full = $last !== false && count($orders) >= $pageSize;
        return new OrdersPage([
            'orders' => $payload,
            'next_updated_since' => $full ? (string)$last['updated_at'] : null,
            'next_after_id' => $full ? (int)$last['entity_id'] : null,
        ]);
    }

    /**
     * Each order's lines, by order id.
     *
     * @param array[] $items
     * @return OrderLine[][] order id => that order's lines
     */
    private function lines(array $items): array
    {
        $children = [];
        foreach ($items as $item) {
            if ($item['parent_item_id'] !== null) {
                $children[(int)$item['parent_item_id']][] = $item;
            }
        }
        $lines = [];
        foreach ($items as $item) {
            if ($item['parent_item_id'] !== null) {
                continue;
            }
            $parts = $children[(int)$item['item_id']] ?? [];
            $lines[(int)$item['order_id']][] = new OrderLine($this->line($item, $parts));
        }
        return $lines;
    }

    /**
     * One line's fields.
     *
     * A configurable line names the simple product it was bought as. A bundle lists its parts, and
     * a dynamically priced bundle (Magento's "children calculated") carries its amounts on the
     * parts, not on itself: the line's amounts are then the parts' sums, as the order totals are.
     *
     * @param array $item The top-level sales_order_item row
     * @param array[] $parts Its child rows
     * @return array
     */
    private function line(array $item, array $parts): array
    {
        $type = $item['product_type'];
        $fromParts = $type === 'bundle' && $parts && $this->childrenCalculated($item);
        $amounts = [];
        foreach (self::LINE_AMOUNTS as $field) {
            $amounts[$field] = $fromParts ? $this->sum(array_column($parts, $field)) : $item[$field];
        }
        $price = $item['price'];
        if ($fromParts) {
            $price = (float)$item['qty_ordered'] > 0
                ? bcdiv((string)$amounts['row_total'], (string)$item['qty_ordered'], 4)
                : '0.0000';
        }
        return [
            'product_id' => (int)$item['product_id'],
            'variant_product_id' => $type === 'configurable' && $parts
                ? (int)$parts[0]['product_id']
                : (int)$item['product_id'],
            'part_product_ids' => $type === 'bundle'
                ? array_map('intval', array_column($parts, 'product_id'))
                : [],
            'sku' => (string)$item['sku'],
            'product_type' => $type,
            'qty_ordered' => $item['qty_ordered'],
            'qty_refunded' => $item['qty_refunded'],
            'price' => $price,
        ] + $amounts;
    }

    /**
     * Whether Magento prices this item from its children (a dynamically priced bundle).
     *
     * @param array $item
     * @return bool
     */
    private function childrenCalculated(array $item): bool
    {
        $options = is_string($item['product_options'] ?? null)
            ? json_decode($item['product_options'], true)
            : null;
        // Magento\Catalog\Model\Product\Type\AbstractType::CALCULATE_CHILD
        return is_array($options)
            && isset($options['product_calculations'])
            && (int)$options['product_calculations'] === 0;
    }

    /**
     * The sum of decimal strings, exactly.
     *
     * @param array $values
     * @return string
     */
    private function sum(array $values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, (string)($value ?? '0'), 4);
        }
        return $total;
    }
}
