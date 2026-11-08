<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model\PriceTest;

/**
 * A cart item's price-test price — a PHP mirror of the Shopify price-overrider Cart Transform
 * (apps/shopify-app/extensions/price-overrider/src/cart_transform_run.rs), reading the same
 * compact config the backend builds:
 *
 *   { "EXP…:variantId": { "on": true, "r": [ { p, t, v } | { p, t, d, o } | { p, vr } ] } }
 *
 * `p` is the product (on Magento: a configurable's parent), `t` the adjustment ('f' fixed
 * price, 'p' signed percentage of the item's own price, 'a' signed amount), `d`/`o` a price
 * per value of the variant title's d-th option, `vr` a rule per variant (on Magento: the
 * configurable's child, or the product itself). Prices are in the store's base currency.
 */
class Resolver
{
    private const EXPERIMENT_KEY = '/^(EXP[A-Z0-9]+):/';

    /**
     * The rules of the shopper's live arms, latest arm per experiment — `latest_key_per_experiment`.
     *
     * A shopper re-bucketed into another arm of an experiment keeps the old key; only the last
     * live one counts. Keys of other kinds pass through.
     *
     * @param string[] $keys the cart's keys, in activation order
     * @param array $config the published arms, by key
     * @return array rules in key order, each as {key, rule} with the key it came from
     */
    public function liveRules(array $keys, array $config): array
    {
        $isLive = fn (string $key) => is_array($config[$key] ?? null) && !empty($config[$key]['on']);
        $lastLive = [];
        foreach ($keys as $index => $key) {
            if (preg_match(self::EXPERIMENT_KEY, $key, $match) && $isLive($key)) {
                $lastLive[$match[1]] = $index;
            }
        }
        $rules = [];
        foreach ($keys as $index => $key) {
            if (preg_match(self::EXPERIMENT_KEY, $key, $match) && isset($lastLive[$match[1]])
                && $lastLive[$match[1]] !== $index) {
                continue;
            }
            if (!$isLive($key)) {
                continue;
            }
            foreach ((array)($config[$key]['r'] ?? []) as $rule) {
                if (is_array($rule)) {
                    $rules[] = ['key' => $key, 'rule' => $rule];
                }
            }
        }
        return $rules;
    }

    /**
     * The first rule for this product that prices this variant — `resolve_compact`.
     *
     * A product's first rule decides: a per-variant rule that does not list the variant, or an
     * option rule without a price for its value, leaves the item at its own price.
     *
     * @param array $rules from liveRules
     * @param string $productId
     * @param string $variantId
     * @param string $variantTitle option values joined with " / ", as the catalog titles variants
     * @return array|null {key, t, v}
     */
    public function resolve(array $rules, string $productId, string $variantId, string $variantTitle): ?array
    {
        foreach ($rules as ['key' => $key, 'rule' => $rule]) {
            if ((string)($rule['p'] ?? '') !== $productId) {
                continue;
            }
            if (is_array($rule['vr'] ?? null)) {
                $over = $rule['vr'][$variantId] ?? null;
                return is_array($over) && is_numeric($over['v'] ?? null)
                    ? ['key' => $key, 't' => (string)($over['t'] ?? 'f'), 'v' => (float)$over['v']]
                    : null;
            }
            if (isset($rule['d']) && is_array($rule['o'] ?? null)) {
                $value = explode(' / ', $variantTitle)[(int)$rule['d']] ?? null;
                $price = $value === null ? null : ($rule['o'][$value] ?? null);
                return is_numeric($price)
                    ? ['key' => $key, 't' => (string)($rule['t'] ?? 'f'), 'v' => (float)$price]
                    : null;
            }
            if (is_numeric($rule['v'] ?? null)) {
                return ['key' => $key, 't' => (string)($rule['t'] ?? 'f'), 'v' => (float)$rule['v']];
            }
        }
        return null;
    }

    /**
     * The unit price a resolved rule gives an item, never below zero — `compute_final_price`.
     *
     * @param float $original the item's own unit price
     * @param array $resolved from resolve
     * @return float
     */
    public function price(float $original, array $resolved): float
    {
        $price = match ($resolved['t']) {
            'p', 'percentage' => $original * (1 + $resolved['v'] / 100),
            'a', 'absolute' => $original + $resolved['v'],
            default => $resolved['v'],
        };
        return max(0.0, round($price, 4));
    }
}
