<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\InputException;
use Variant\Connector\Api\Data\OrdersPageInterface;
use Variant\Connector\Api\OrdersInterface;

/**
 * Reads the orders feed straight from sales_order and sales_order_item: three indexed queries a
 * page (orders by Magento's own SALES_ORDER_UPDATED_AT index, their items, their customers' first
 * orders), without loading order models, so a large store is cheap to poll.
 *
 * Pages run on (updated_at, entity_id). updated_at has one-second precision, so a page could end
 * inside a second in which an order it already passed changes again; a later page would then never
 * see that change. Only changes at least SETTLE_SECONDS old are returned, so every second a page
 * reaches is over: nothing can still change within it.
 */
class Orders implements OrdersInterface
{
    public const MAX_PAGE_SIZE = 500;
    /** Changes younger than this wait for a later read; also covers transactions still committing. */
    public const SETTLE_SECONDS = 60;

    private const ORDER_COLUMNS = [
        'entity_id', 'increment_id', 'store_id', 'state', 'status', 'created_at', 'updated_at',
        'customer_id', 'order_currency_code', 'grand_total', 'subtotal', 'discount_amount',
        'tax_amount', 'shipping_amount', 'total_refunded', 'base_currency_code', 'base_grand_total',
        'base_total_refunded', VisitorIds::USER_FIELD, VisitorIds::SESSION_FIELD,
    ];

    private const ITEM_COLUMNS = [
        'item_id', 'order_id', 'parent_item_id', 'product_id', 'product_type', 'sku', 'qty_ordered',
        'qty_refunded', 'price', 'row_total', 'discount_amount', 'tax_amount', 'amount_refunded',
        'product_options',
    ];

    /**
     * @param ResourceConnection $resource
     * @param OrderPayload $payload
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly OrderPayload $payload
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getUpdatedSince(
        string $updatedSince,
        int $afterId = 0,
        int $pageSize = 100
    ): OrdersPageInterface {
        $since = $this->parseSince($updatedSince);
        if ($afterId < 0) {
            throw new InputException(__('afterId must be 0 or more.'));
        }
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InputException(__('pageSize must be between 1 and %1.', self::MAX_PAGE_SIZE));
        }

        $connection = $this->resource->getConnection('sales');
        $orderTable = $this->resource->getTableName('sales_order', 'sales');
        $orders = $connection->fetchAll(
            $connection->select()
                ->from($orderTable, self::ORDER_COLUMNS)
                ->where(
                    $connection->quoteInto('updated_at > ?', $since)
                    . ' OR (' . $connection->quoteInto('updated_at = ?', $since)
                    . ' AND ' . $connection->quoteInto('entity_id > ?', $afterId) . ')'
                )
                // The database's own clock, in the session time zone updated_at is read in.
                ->where('updated_at < NOW() - INTERVAL ' . self::SETTLE_SECONDS . ' SECOND')
                ->order(['updated_at ASC', 'entity_id ASC'])
                ->limit($pageSize)
        );

        $items = [];
        $firstOrderIds = [];
        if ($orders) {
            $items = $connection->fetchAll(
                $connection->select()
                    ->from($this->resource->getTableName('sales_order_item', 'sales'), self::ITEM_COLUMNS)
                    ->where('order_id IN (?)', array_column($orders, 'entity_id'))
                    ->order('item_id ASC')
            );
            $customerIds = array_values(array_unique(array_filter(array_column($orders, 'customer_id'))));
            if ($customerIds) {
                $firstOrderIds = array_map(
                    'intval',
                    $connection->fetchPairs(
                        $connection->select()
                            ->from($orderTable, ['customer_id', 'first_id' => new Expression('MIN(entity_id)')])
                            ->where('customer_id IN (?)', $customerIds)
                            ->group('customer_id')
                    )
                );
            }
        }

        return $this->payload->build($orders, $items, $firstOrderIds, $pageSize);
    }

    /**
     * The point to read from, as sales_order.updated_at holds it (UTC).
     *
     * @param string $value "Y-m-d H:i:s", or "Y-m-d" for midnight
     * @return string
     * @throws InputException
     */
    public function parseSince(string $value): string
    {
        $value = trim($value);
        foreach (['!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InputException(__('updatedSince must be a UTC time like 2026-11-09 14:00:00.'));
    }
}
