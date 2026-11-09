<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Variant\Connector\Api\Data\OrderInterface;

/**
 * Read-only; built by Variant\Connector\Model\OrderPayload.
 */
class Order extends AbstractSimpleObject implements OrderInterface
{
    /**
     * @inheritdoc
     */
    public function getId(): int
    {
        return $this->_get(self::ID);
    }

    /**
     * @inheritdoc
     */
    public function getIncrementId(): string
    {
        return $this->_get(self::INCREMENT_ID);
    }

    /**
     * @inheritdoc
     */
    public function getStoreId(): int
    {
        return $this->_get(self::STORE_ID);
    }

    /**
     * @inheritdoc
     */
    public function getCreatedAt(): string
    {
        return $this->_get(self::CREATED_AT);
    }

    /**
     * @inheritdoc
     */
    public function getUpdatedAt(): string
    {
        return $this->_get(self::UPDATED_AT);
    }

    /**
     * @inheritdoc
     */
    public function getState(): ?string
    {
        return $this->_get(self::STATE);
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): ?string
    {
        return $this->_get(self::STATUS);
    }

    /**
     * @inheritdoc
     */
    public function getVariantUserId(): ?string
    {
        return $this->_get(self::VARIANT_USER_ID);
    }

    /**
     * @inheritdoc
     */
    public function getVariantSessionId(): ?string
    {
        return $this->_get(self::VARIANT_SESSION_ID);
    }

    /**
     * @inheritdoc
     */
    public function getCurrency(): ?string
    {
        return $this->_get(self::CURRENCY);
    }

    /**
     * @inheritdoc
     */
    public function getGrandTotal(): ?string
    {
        return $this->_get(self::GRAND_TOTAL);
    }

    /**
     * @inheritdoc
     */
    public function getSubtotal(): ?string
    {
        return $this->_get(self::SUBTOTAL);
    }

    /**
     * @inheritdoc
     */
    public function getDiscountAmount(): ?string
    {
        return $this->_get(self::DISCOUNT_AMOUNT);
    }

    /**
     * @inheritdoc
     */
    public function getTaxAmount(): ?string
    {
        return $this->_get(self::TAX_AMOUNT);
    }

    /**
     * @inheritdoc
     */
    public function getShippingAmount(): ?string
    {
        return $this->_get(self::SHIPPING_AMOUNT);
    }

    /**
     * @inheritdoc
     */
    public function getTotalRefunded(): ?string
    {
        return $this->_get(self::TOTAL_REFUNDED);
    }

    /**
     * @inheritdoc
     */
    public function getBaseCurrency(): ?string
    {
        return $this->_get(self::BASE_CURRENCY);
    }

    /**
     * @inheritdoc
     */
    public function getBaseGrandTotal(): ?string
    {
        return $this->_get(self::BASE_GRAND_TOTAL);
    }

    /**
     * @inheritdoc
     */
    public function getBaseTotalRefunded(): ?string
    {
        return $this->_get(self::BASE_TOTAL_REFUNDED);
    }

    /**
     * @inheritdoc
     */
    public function getIsFirstOrder(): ?bool
    {
        return $this->_get(self::IS_FIRST_ORDER);
    }

    /**
     * @inheritdoc
     */
    public function getLines(): array
    {
        return $this->_get(self::LINES) ?? [];
    }
}
