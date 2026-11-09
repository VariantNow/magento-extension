<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Api\Data;

/**
 * One line of an order: a top-level item. A configurable product's line names the parent product
 * (as Variant's catalog does) and the simple product it was bought as. A dynamically priced
 * bundle's amounts are the sums of its parts', as in the order totals.
 */
interface OrderLineInterface
{
    public const PRODUCT_ID = 'product_id';
    public const VARIANT_PRODUCT_ID = 'variant_product_id';
    public const PART_PRODUCT_IDS = 'part_product_ids';
    public const SKU = 'sku';
    public const PRODUCT_TYPE = 'product_type';
    public const QTY_ORDERED = 'qty_ordered';
    public const QTY_REFUNDED = 'qty_refunded';
    public const PRICE = 'price';
    public const ROW_TOTAL = 'row_total';
    public const DISCOUNT_AMOUNT = 'discount_amount';
    public const TAX_AMOUNT = 'tax_amount';
    public const AMOUNT_REFUNDED = 'amount_refunded';

    /**
     * The product, the parent of a configurable
     *
     * @return int
     */
    public function getProductId(): int;

    /**
     * The simple product it was bought as; the product itself when it has no variants
     *
     * @return int
     */
    public function getVariantProductId(): int;

    /**
     * A bundle's parts: the products chosen in it; empty for other products
     *
     * @return int[]
     */
    public function getPartProductIds(): array;

    /**
     * The SKU bought
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Magento's product type: simple, configurable, bundle…
     *
     * @return string|null
     */
    public function getProductType(): ?string;

    /**
     * Quantity ordered (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getQtyOrdered(): ?string;

    /**
     * Quantity refunded (a decimal string, never rounded)
     *
     * For a dynamically priced bundle Magento counts the whole bundle as refunded when any part is;
     * read amount_refunded there.
     *
     * @return string|null
     */
    public function getQtyRefunded(): ?string;

    /**
     * Unit price, in the order currency (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getPrice(): ?string;

    /**
     * Price × quantity, before discounts (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getRowTotal(): ?string;

    /**
     * Discount on the line (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getDiscountAmount(): ?string;

    /**
     * Tax on the line (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getTaxAmount(): ?string;

    /**
     * Amount refunded on the line (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getAmountRefunded(): ?string;
}
