<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Api;

/**
 * The store's orders as Variant's test results read them: who placed each one (the Variant
 * visitor and session ids the cart carried), what it was worth, and what was refunded. No names,
 * emails or addresses.
 *
 * Read in pages, oldest change first, so Variant can poll for orders created or changed since
 * its last read and pick up later refunds and cancellations too.
 */
interface OrdersInterface
{
    /**
     * Orders created or changed since a point, oldest change first.
     *
     * Start with `updatedSince` alone. While the response has `next_updated_since`, pass it and
     * `next_after_id` as `updatedSince` and `afterId` to read the following page. Changes from
     * the last minute wait for a later read (Orders::SETTLE_SECONDS), so an order changed again
     * within the second a page ended on is never skipped.
     *
     * @param string $updatedSince UTC, "Y-m-d H:i:s" (Magento's own updated_at)
     * @param int $afterId Order id the previous page ended on, for orders changed in its last second
     * @param int $pageSize 1 to 500
     * @return \Variant\Connector\Api\Data\OrdersPageInterface
     */
    public function getUpdatedSince(
        string $updatedSince,
        int $afterId = 0,
        int $pageSize = 100
    ): \Variant\Connector\Api\Data\OrdersPageInterface;
}
