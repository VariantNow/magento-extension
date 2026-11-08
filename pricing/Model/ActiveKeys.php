<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model;

/**
 * The shopper's activated experiment arms: the `variant_discounts` cookie the storefront
 * sets (Magento's stand-in for Shopify's `_active_discount_experiments` cart attribute),
 * kept on the cart and copied to the order.
 */
class ActiveKeys
{
    public const COOKIE = 'variant_discounts';
    public const FIELD = 'variant_discount_keys';
    /** Cart item field (and add-to-cart parameter) carrying a recommendation or checkout-card receipt. */
    public const RECEIPT_FIELD = 'variant_rec_token';
    /** The shopper's activated price-test arms: Magento's stand-in for Shopify's `_active_experiments`. */
    public const PRICE_COOKIE = 'variant_prices';
    public const PRICE_FIELD = 'variant_price_keys';
    /** Cart and order item field: the arm whose price test priced the item. */
    public const ITEM_PRICE_FIELD = 'variant_price_key';
    /** Cookie => cart field, for every list of keys the storefront activates. */
    public const COOKIE_FIELDS = [self::COOKIE => self::FIELD, self::PRICE_COOKIE => self::PRICE_FIELD];
    /** `<test id>:<variant id>`; a recommendation's draft runs under its AR… id. */
    private const KEY = '/^(EXP[A-Z0-9]+|AR\d+):[A-Za-z0-9_-]{1,64}$/';
    private const MAX_KEYS = 20;

    /**
     * Valid keys from a raw comma-separated value, in order, without duplicates.
     *
     * @param string|null $raw
     * @return string[]
     */
    public function parse(?string $raw): array
    {
        $keys = [];
        foreach (explode(',', (string)$raw) as $key) {
            $key = trim($key);
            if (preg_match(self::KEY, $key) && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return array_slice($keys, -self::MAX_KEYS);
    }

    /**
     * The keys as the cart field stores them, comma-separated; null when there are none.
     *
     * @param string[] $keys
     * @return string|null
     */
    public function format(array $keys): ?string
    {
        return $keys ? implode(',', $keys) : null;
    }
}
