<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Api\Data;

/**
 * One order as Variant's test results read it: who placed it, what it was worth, what was refunded.
 * No names, emails or addresses. Unknown values are left out of the response.
 */
interface OrderInterface
{
    public const ID = 'id';
    public const INCREMENT_ID = 'increment_id';
    public const STORE_ID = 'store_id';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const STATE = 'state';
    public const STATUS = 'status';
    public const VARIANT_USER_ID = 'variant_user_id';
    public const VARIANT_SESSION_ID = 'variant_session_id';
    public const CURRENCY = 'currency';
    public const GRAND_TOTAL = 'grand_total';
    public const SUBTOTAL = 'subtotal';
    public const DISCOUNT_AMOUNT = 'discount_amount';
    public const TAX_AMOUNT = 'tax_amount';
    public const SHIPPING_AMOUNT = 'shipping_amount';
    public const TOTAL_REFUNDED = 'total_refunded';
    public const BASE_CURRENCY = 'base_currency';
    public const BASE_GRAND_TOTAL = 'base_grand_total';
    public const BASE_TOTAL_REFUNDED = 'base_total_refunded';
    public const IS_FIRST_ORDER = 'is_first_order';
    public const LINES = 'lines';

    /**
     * The order's entity id
     *
     * @return int
     */
    public function getId(): int;

    /**
     * The order number shoppers see
     *
     * @return string
     */
    public function getIncrementId(): string;

    /**
     * The store view it was placed in
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * When it was placed, UTC
     *
     * @return string
     */
    public function getCreatedAt(): string;

    /**
     * When it last changed, UTC
     *
     * @return string
     */
    public function getUpdatedAt(): string;

    /**
     * Magento's order state: new, processing, complete, canceled, closed…
     *
     * @return string|null
     */
    public function getState(): ?string;

    /**
     * The order status
     *
     * @return string|null
     */
    public function getStatus(): ?string;

    /**
     * The Variant visitor id of the shopper who placed it, with analytics consent
     *
     * @return string|null
     */
    public function getVariantUserId(): ?string;

    /**
     * The Variant session it was placed in
     *
     * @return string|null
     */
    public function getVariantSessionId(): ?string;

    /**
     * The order currency
     *
     * @return string|null
     */
    public function getCurrency(): ?string;

    /**
     * Grand total (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getGrandTotal(): ?string;

    /**
     * Subtotal (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getSubtotal(): ?string;

    /**
     * Discount (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getDiscountAmount(): ?string;

    /**
     * Tax (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getTaxAmount(): ?string;

    /**
     * Shipping (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getShippingAmount(): ?string;

    /**
     * Refunded so far (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getTotalRefunded(): ?string;

    /**
     * The store's base currency
     *
     * @return string|null
     */
    public function getBaseCurrency(): ?string;

    /**
     * Grand total in the base currency (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getBaseGrandTotal(): ?string;

    /**
     * Refunded so far, in the base currency (a decimal string, never rounded)
     *
     * @return string|null
     */
    public function getBaseTotalRefunded(): ?string;

    /**
     * Whether it is the customer's first order; known for signed-in customers only
     *
     * @return bool|null
     */
    public function getIsFirstOrder(): ?bool;

    /**
     * The order's lines
     *
     * @return \Variant\Connector\Api\Data\OrderLineInterface[]
     */
    public function getLines(): array;
}
