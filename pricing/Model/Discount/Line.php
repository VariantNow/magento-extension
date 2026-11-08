<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model\Discount;

/**
 * One cart line as the calculator sees it — Magento's counterpart of the Shopify function's
 * PackLineInput. Money is in the store's base currency, the currency rules are written in.
 */
class Line
{
    /**
     * @param string $id
     * @param int $quantity
     * @param float $subtotal row total before discounts
     * @param string $productId the product Magento prices: a configurable's parent, not its child
     * @param string[] $productRefs ids and SKUs a rule may name: the product's, and the chosen child's
     * @param string[] $categoryIds
     * @param string|null $recClaim the item's receipt, if a recommendation or checkout card added it
     * @param float $existingDiscount what the store's own rules already took off
     * @param bool $subscription always false on Magento Open Source, which has no subscriptions
     * @param string|null $bundleId the Variant bundle the item was added as part of
     * @param string|null $bundleInstance the add that put it in the cart, shared by that add's items
     */
    public function __construct(
        public readonly string $id,
        public readonly int $quantity,
        public readonly float $subtotal,
        public readonly string $productId,
        public readonly array $productRefs,
        public readonly array $categoryIds = [],
        public readonly ?string $recClaim = null,
        public readonly float $existingDiscount = 0.0,
        public readonly bool $subscription = false,
        public readonly ?string $bundleId = null,
        public readonly ?string $bundleInstance = null
    ) {
    }

    /**
     * What is left to discount on this line after the store's own rules.
     *
     * @return float
     */
    public function available(): float
    {
        return max(0.0, round($this->subtotal - $this->existingDiscount, 2));
    }

    /**
     * Whether a product-scoped rule names this line's product or one of its categories.
     *
     * @param mixed $products
     * @param mixed $categories
     * @return bool
     */
    public function matches($products, $categories): bool
    {
        $products = is_array($products) ? array_map('strval', $products) : [];
        $categories = is_array($categories) ? array_map('strval', $categories) : [];
        return (bool)array_intersect($this->productRefs, $products)
            || (bool)array_intersect($this->categoryIds, $categories);
    }
}
