<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Variant\Connector\Api\Data\OrdersPageInterface;

/**
 * Read-only; built by Variant\Connector\Model\OrderPayload.
 */
class OrdersPage extends AbstractSimpleObject implements OrdersPageInterface
{
    /**
     * @inheritdoc
     */
    public function getOrders(): array
    {
        return $this->_get(self::ORDERS) ?? [];
    }

    /**
     * @inheritdoc
     */
    public function getNextUpdatedSince(): ?string
    {
        return $this->_get(self::NEXT_UPDATED_SINCE);
    }

    /**
     * @inheritdoc
     */
    public function getNextAfterId(): ?int
    {
        return $this->_get(self::NEXT_AFTER_ID);
    }
}
