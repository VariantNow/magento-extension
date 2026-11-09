<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model\Data;

use Magento\Framework\Api\AbstractSimpleObject;
use Variant\Connector\Api\Data\OrderLineInterface;

/**
 * Read-only; built by Variant\Connector\Model\OrderPayload.
 */
class OrderLine extends AbstractSimpleObject implements OrderLineInterface
{
    /**
     * @inheritdoc
     */
    public function getProductId(): int
    {
        return $this->_get(self::PRODUCT_ID);
    }

    /**
     * @inheritdoc
     */
    public function getVariantProductId(): int
    {
        return $this->_get(self::VARIANT_PRODUCT_ID);
    }

    /**
     * @inheritdoc
     */
    public function getSku(): string
    {
        return $this->_get(self::SKU);
    }

    /**
     * @inheritdoc
     */
    public function getProductType(): ?string
    {
        return $this->_get(self::PRODUCT_TYPE);
    }

    /**
     * @inheritdoc
     */
    public function getQtyOrdered(): ?string
    {
        return $this->_get(self::QTY_ORDERED);
    }

    /**
     * @inheritdoc
     */
    public function getQtyRefunded(): ?string
    {
        return $this->_get(self::QTY_REFUNDED);
    }

    /**
     * @inheritdoc
     */
    public function getPrice(): ?string
    {
        return $this->_get(self::PRICE);
    }

    /**
     * @inheritdoc
     */
    public function getRowTotal(): ?string
    {
        return $this->_get(self::ROW_TOTAL);
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
    public function getPartProductIds(): array
    {
        return $this->_get(self::PART_PRODUCT_IDS) ?? [];
    }

    /**
     * @inheritdoc
     */
    public function getAmountRefunded(): ?string
    {
        return $this->_get(self::AMOUNT_REFUNDED);
    }
}
