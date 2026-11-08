<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model\Discount;

/**
 * Bundle discounts — a port of `buildBundleCandidates` in Variant's Shopify bundle discount
 * Function, reading the same compact config:
 * {"bundles": {"<id>": {"t": "f"|"p", "v": number, "n": name, "i": {productId: units}}}}.
 *
 * A bundle is added as ordinary cart items tagged with the bundle's id and a per-add instance
 * id, and every instance is priced on its own. The tags survive whatever the shopper does to an
 * item afterwards, so a fixed-price bundle is priced against its configured composition (`i`),
 * not against what the tagged items add up to: only the configured units count toward the
 * component total, the gap to the bundle price is the discount, and an incomplete bundle gets
 * nothing. A percentage bundle discounts its items while the instance still holds as many
 * distinct products as the bundle has.
 *
 * Shopify's product grouping (another colour sold as its own product filling a slot) has no
 * Magento counterpart: a configurable's colours are its children, which the parent item already
 * covers. Money is in the store's base currency, the currency bundle prices are written in.
 */
class Bundles
{
    /**
     * Discounts by line id: amount and the offer name shown in "Discount (…)".
     *
     * @param array $config the published config
     * @param Line[] $lines
     * @return array<string, array{amount: float, messages: string[]}>
     */
    public function calculate(array $config, array $lines): array
    {
        $bundles = is_array($config['bundles'] ?? null) ? $config['bundles'] : [];
        $groups = [];
        foreach ($lines as $line) {
            if ($line->bundleId === null || $line->bundleInstance === null) {
                continue;
            }
            $groups[$line->bundleInstance] ??= ['bundleId' => $line->bundleId, 'lines' => []];
            $groups[$line->bundleInstance]['lines'][] = $line;
        }

        $out = [];
        foreach ($groups as $group) {
            $bundle = $bundles[$group['bundleId']] ?? null;
            if (!is_array($bundle)) {
                continue;
            }
            $amounts = ($bundle['t'] ?? null) === 'p'
                ? $this->percentage($bundle, $group['lines'])
                : $this->fixed($bundle, $group['lines']);
            $message = $this->message($bundle);
            foreach ($amounts as $id => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $out[$id] ??= ['amount' => 0.0, 'messages' => []];
                $out[$id]['amount'] = round($out[$id]['amount'] + $amount, 2);
                if (!in_array($message, $out[$id]['messages'], true)) {
                    $out[$id]['messages'][] = $message;
                }
            }
        }
        return $out;
    }

    /**
     * A percentage bundle: every tagged item, while no configured product is missing.
     *
     * @param array $bundle
     * @param Line[] $lines
     * @return array<string, float>
     */
    private function percentage(array $bundle, array $lines): array
    {
        $value = $this->clampPercent((float)($bundle['v'] ?? 0));
        $units = $this->composition($bundle);
        // Every configured product, in its configured units, or nothing. The tags are written
        // by the storefront, so an add of other products tagged with this bundle gets nothing,
        // and only the configured products are discounted.
        if ($value <= 0 || ($units !== null && $this->exactUnits($units, $lines) === null)) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            if ($units === null || isset($units[$line->productId])) {
                $out[$line->id] = min($line->available(), round($line->subtotal * $value / 100, 2));
            }
        }
        return $out;
    }

    /**
     * A fixed-price bundle: the configured units' cost minus the bundle price.
     *
     * Spread over the items holding those units in proportion to what each one's share costs.
     *
     * @param array $bundle
     * @param Line[] $lines
     * @return array<string, float>
     */
    private function fixed(array $bundle, array $lines): array
    {
        $price = (float)($bundle['v'] ?? -1);
        if (!is_finite($price) || $price < 0) {
            return [];
        }
        $units = $this->composition($bundle);
        if ($units === null) {
            return $this->legacyFixed($price, $lines);
        }
        $taken = $this->exactUnits($units, $lines);
        if ($taken === null) {
            return [];
        }
        $costs = [];
        foreach ($lines as $index => $line) {
            if ($taken[$index] > 0) {
                $costs[$line->id] = $taken[$index] * $this->unitPrice($line);
            }
        }
        $amount = round(round(array_sum($costs), 2) - round($price, 2), 2);
        if ($amount <= 0) {
            return [];
        }
        return $this->spread($amount, $costs, $lines);
    }

    /**
     * Configs written before the composition existed.
     *
     * The percentage that brings the tagged items' live subtotal down to the bundle price, as the
     * Shopify Function still does for them.
     *
     * @param float $price
     * @param Line[] $lines
     * @return array<string, float>
     */
    private function legacyFixed(float $price, array $lines): array
    {
        $total = array_sum(array_map(fn (Line $line) => $line->subtotal, $lines));
        if ($total <= 0 || $price >= $total) {
            return [];
        }
        return $this->percentage(['v' => (1 - $price / $total) * 100], $lines);
    }

    /**
     * Units each line contributes, by line index; null when the bundle is incomplete.
     *
     * Each product's configured units are drawn from its own lines in cart order.
     *
     * @param array $units product id => units
     * @param Line[] $lines
     * @return int[]|null
     */
    private function exactUnits(array $units, array $lines): ?array
    {
        $remaining = $units;
        $taken = [];
        foreach ($lines as $index => $line) {
            $taken[$index] = 0;
            $left = $remaining[$line->productId] ?? 0;
            if ($left > 0) {
                $taken[$index] = min($left, max(0, $line->quantity));
                $remaining[$line->productId] = $left - $taken[$index];
            }
        }
        foreach ($remaining as $left) {
            if ($left > 0) {
                return null;
            }
        }
        return $taken;
    }

    /**
     * Split an amount over lines by weight, in cents.
     *
     * The rounding remainder goes on the last line, and no line gets more than it has left after
     * the store's own rules.
     *
     * @param float $amount
     * @param array $weights line id => weight
     * @param Line[] $lines
     * @return array<string, float>
     */
    private function spread(float $amount, array $weights, array $lines): array
    {
        $available = [];
        foreach ($lines as $line) {
            $available[$line->id] = $line->available();
        }
        $total = array_sum($weights);
        $ids = array_keys($weights);
        $last = end($ids);
        $out = [];
        $left = $amount;
        foreach ($weights as $id => $weight) {
            // Earlier shares rounded up can leave the last line a cent short of nothing.
            $share = $id === $last ? max(0.0, $left) : round($amount * $weight / $total, 2);
            $out[$id] = min($available[$id], $share);
            $left = round($left - $share, 2);
        }
        return $out;
    }

    /**
     * The composition, usable entries only, or null when the config carries none.
     *
     * @param array $bundle
     * @return array<string, int>|null
     */
    private function composition(array $bundle): ?array
    {
        if (!is_array($bundle['i'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($bundle['i'] as $productId => $raw) {
            $units = (int)floor((float)$raw);
            if ($units > 0) {
                $out[(string)$productId] = $units;
            }
        }
        return $out ?: null;
    }

    /**
     * One unit's price on the line.
     *
     * @param Line $line
     * @return float
     */
    private function unitPrice(Line $line): float
    {
        return $line->quantity > 0 ? $line->subtotal / $line->quantity : 0.0;
    }

    /**
     * The percent within 0–100.
     *
     * @param float $value
     * @return float
     */
    private function clampPercent(float $value): float
    {
        if (!is_finite($value) || $value <= 0) {
            return 0.0;
        }
        return min(100.0, $value);
    }

    /**
     * The offer name shown in "Discount (…)".
     *
     * @param array $bundle
     * @return string
     */
    private function message(array $bundle): string
    {
        $name = is_string($bundle['n'] ?? null) ? trim($bundle['n']) : '';
        return $name !== '' ? $name . ' discount' : 'Bundle discount';
    }
}
