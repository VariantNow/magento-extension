<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Api\Data;

/**
 * One page of the orders feed (Variant\Connector\Api\OrdersInterface).
 */
interface OrdersPageInterface
{
    public const ORDERS = 'orders';
    public const NEXT_UPDATED_SINCE = 'next_updated_since';
    public const NEXT_AFTER_ID = 'next_after_id';

    /**
     * Orders created or changed, oldest change first
     *
     * @return \Variant\Connector\Api\Data\OrderInterface[]
     */
    public function getOrders(): array;

    /**
     * Pass as updatedSince to read the next page; absent on the last page
     *
     * @return string|null
     */
    public function getNextUpdatedSince(): ?string;

    /**
     * Pass as afterId to read the next page; absent on the last page
     *
     * @return int|null
     */
    public function getNextAfterId(): ?int;
}
