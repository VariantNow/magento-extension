<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model\Discount;

/**
 * Each cart line's experiment discount — a PHP MIRROR of Variant's Shopify experiment discount
 * Function (its active keys, packs and receipt tokens), reading the same compact config the
 * backend builds:
 *
 *   { "EXP…:variantId": { "on": true, "r": [ { t, v, s, p, c, rid, m, q, sq, cv, pt, co } ] } }
 *
 * Function-for-function with packs.ts so a change to one is easy to carry to the other:
 * `allocatePacks` decides which units each rule covers, `productCandidates` turns a line's
 * allocations into one discount, `orderCandidates` builds the order discounts (biggest wins).
 * `settle` is the Magento-only step Shopify's checkout does for the function: turning
 * candidates into money per line.
 *
 * Money is in the store's base currency, which is what rules are written in, so the rate
 * the Shopify function uses for presentment currency is always 1 here; the plugin converts
 * the result to the cart's currency.
 */
class Calculator
{
    private const EXPERIMENT_KEY = '/^(EXP[A-Z0-9]+):/';

    /**
     * @param SipHash $sipHash
     */
    public function __construct(
        private readonly SipHash $sipHash
    ) {
    }

    /**
     * The shopper's latest arm per experiment, switched on — active-keys.ts `ownLiveKeys`.
     *
     * @param string[] $keys in activation order
     * @param array $experiments
     * @return string[]
     */
    public function liveKeys(array $keys, array $experiments): array
    {
        $lastIndex = [];
        foreach ($keys as $index => $key) {
            if (preg_match(self::EXPERIMENT_KEY, $key, $m)) {
                $lastIndex[$m[1]] = $index;
            }
        }
        $live = [];
        foreach ($keys as $index => $key) {
            if (preg_match(self::EXPERIMENT_KEY, $key, $m) && $lastIndex[$m[1]] !== $index) {
                continue;
            }
            if (!empty($experiments[$key]['on']) && is_array($experiments[$key]['r'] ?? null)) {
                $live[] = $key;
            }
        }
        return array_values(array_unique($live));
    }

    /**
     * The discount for each line, never more than what the store's own rules left on it.
     *
     * @param array $experiments
     * @param string[] $keys
     * @param Line[] $lines
     * @param string[] $recKeys receipt signing keys by key id
     * @return array<string, array{amount: float, message: string, messages: string[]}> keyed by line id;
     *         `message` names the biggest offer on the line, `messages` every offer on it
     */
    public function calculate(array $experiments, array $keys, array $lines, array $recKeys = []): array
    {
        $live = $this->liveKeys($keys, $experiments);
        if (!$live || !$lines) {
            return [];
        }
        $receipts = new Receipts($this->sipHash, $recKeys);
        return $this->settle(
            $lines,
            $this->productCandidates($live, $experiments, $lines, $receipts, $keys),
            $this->orderCandidates($live, $experiments, $lines, $receipts)
        );
    }

    /**
     * Which units of which lines each rule covers — packs.ts `allocatePacks`.
     *
     * @param string[] $activeKeys
     * @param array $experiments
     * @param Line[] $lines
     * @param Receipts $receipts
     * @param string[]|null $cartKeys every key on the cart, this calculation's or not (default: $activeKeys)
     * @return array[] allocations: rule, q (0 whole lines, 1 single units, >=2 packs), byLine, lines, capped
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function allocatePacks(
        array $activeKeys,
        array $experiments,
        array $lines,
        Receipts $receipts,
        ?array $cartKeys = null
    ): array {
        $cartKeys ??= $activeKeys;
        $allocations = [];
        // A card-added line takes one checkout-card rule across every active key, so card deals never stack.
        $cardClaimed = [];

        foreach ($activeKeys as $key) {
            $config = $experiments[$key] ?? null;
            if (!is_array($config) || empty($config['on']) || !is_array($config['r'] ?? null)) {
                continue;
            }
            $packRules = [];
            $wholeCart = [];
            $hasRealPack = false;

            foreach ($this->cardRulesFirst($config['r']) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $resolved = $this->resolveTargetLines($rule, $lines, $receipts);
                // `st`: only items added through a stamped product form. Recipe rules already need
                // a recipe receipt on the same field, which proves where the item came from.
                if (!empty($config['st']) && ($rule['s'] ?? null) === 'p') {
                    $resolved = array_values(array_filter(
                        $resolved,
                        fn (Line $l) => $this->stampQualifies($l, (string)$key, $lines, $cartKeys, $receipts)
                    ));
                }
                $targets = !empty($rule['co'])
                    ? array_values(array_filter($resolved, fn (Line $l) => !isset($cardClaimed[$l->id])))
                    : $resolved;
                if (!$targets) {
                    continue;
                }
                // A 0% base with quantity tiers is "nothing until N items": the tiers still count.
                $tiered = is_array($rule['qt'] ?? null);
                if (!$this->hasPositiveValue($rule) && !$tiered) {
                    continue;
                }
                $cap = $this->cartValueCap($rule, $lines, $resolved);
                if ($cap === 0) {
                    continue;
                }
                if (!empty($rule['co'])) {
                    foreach ($targets as $line) {
                        $cardClaimed[$line->id] = true;
                    }
                }

                $samePack = $this->normalizePack($rule['sq'] ?? null);
                $q = $samePack ?? $this->normalizePack($rule['q'] ?? null);

                // Order-scope rules never compete for units: they cover the whole cart.
                if ($q === null && ($rule['s'] ?? null) === 'o') {
                    $wholeCart[] = ['rule' => $rule, 'targets' => $targets];
                    continue;
                }
                if ($q !== null) {
                    $hasRealPack = true;
                }
                // Quantity tiers: each pool (one per product for a same-product rule) gets the
                // tier it reaches, as one pack of all its units at that value, or the base pack.
                if ($q !== null && $tiered) {
                    $sameProduct = $samePack !== null;
                    foreach ($this->drawPools(['targets' => $targets, 'sameProduct' => $sameProduct]) as $pool) {
                        $tier = $this->quantityTier($rule, $pool);
                        if ($tier !== null) {
                            $packRules[] = [
                                'rule' => ['v' => $tier['v']] + $rule, 'q' => $tier['q'],
                                'sameProduct' => $sameProduct, 'targets' => $pool, 'limit' => null, 'pools' => null,
                            ];
                        } elseif ($this->hasPositiveValue($rule)) {
                            $packRules[] = [
                                'rule' => $rule, 'q' => $q, 'sameProduct' => $sameProduct,
                                'targets' => $pool, 'limit' => null, 'pools' => null,
                            ];
                        }
                    }
                    continue;
                }
                $packRules[] = [
                    'rule' => $rule,
                    'q' => $q ?? 1,
                    'sameProduct' => $samePack !== null,
                    'targets' => $targets,
                    'limit' => $cap,
                    'pools' => null,
                ];
            }

            // No pack in play: pack-less product rules cover their lines whole; a capped
            // cart-value tier draws only its cap, priciest units first.
            if (!$hasRealPack) {
                foreach ($packRules as $pr) {
                    if ($pr['limit'] === null) {
                        $wholeCart[] = ['rule' => $pr['rule'], 'targets' => $pr['targets']];
                        continue;
                    }
                    $byLine = [];
                    $need = $pr['limit'];
                    foreach ($this->drawPools($pr)[0] ?? [] as $line) {
                        if ($need === 0) {
                            break;
                        }
                        $take = min($this->lineQuantity($line), $need);
                        if ($take > 0) {
                            $byLine[$line->id] = $take;
                        }
                        $need -= $take;
                    }
                    if ($byLine) {
                        $allocations[] = [
                            'rule' => $pr['rule'], 'q' => 1, 'byLine' => $byLine,
                            'lines' => $pr['targets'], 'capped' => true,
                        ];
                    }
                }
                $packRules = [];
            }

            foreach ($wholeCart as $whole) {
                $allocations[] = [
                    'rule' => $whole['rule'], 'q' => 0, 'byLine' => [],
                    'lines' => $whole['targets'], 'capped' => false,
                ];
            }
            if (!$packRules) {
                continue;
            }

            // One shared pool of units per experiment key; each rule draws only from its own lines.
            $remaining = [];
            foreach ($packRules as $pr) {
                foreach ($pr['targets'] as $line) {
                    $remaining[$line->id] = $remaining[$line->id] ?? $this->lineQuantity($line);
                }
            }
            // Largest pack first; at equal size a capped tier first. usort is stable in PHP 8.
            usort($packRules, fn ($a, $b) => ($b['q'] <=> $a['q'])
                ?: ((int)($b['limit'] !== null) <=> (int)($a['limit'] !== null)));

            $drawn = [];
            $drawnUnits = [];
            while (true) {
                $index = -1;
                $pool = null;
                foreach ($packRules as $i => &$candidate) {
                    if ($candidate['limit'] !== null && ($drawnUnits[$i] ?? 0) >= $candidate['limit']) {
                        continue;
                    }
                    $candidate['pools'] ??= $this->drawPools($candidate);
                    foreach ($candidate['pools'] as $group) {
                        $units = array_sum(array_map(fn (Line $l) => $remaining[$l->id] ?? 0, $group));
                        if ($units >= $candidate['q']) {
                            $pool = $group;
                            break;
                        }
                    }
                    if ($pool !== null) {
                        $index = $i;
                        break;
                    }
                }
                unset($candidate);
                if ($index === -1 || $pool === null) {
                    break;
                }

                $pr = $packRules[$index];
                $got = $drawn[$index] ?? [];
                $need = $pr['q'];
                foreach ($pool as $line) {
                    if ($need === 0) {
                        break;
                    }
                    $available = $remaining[$line->id] ?? 0;
                    if ($available === 0) {
                        continue;
                    }
                    $take = min($available, $need);
                    $remaining[$line->id] = $available - $take;
                    $got[$line->id] = ($got[$line->id] ?? 0) + $take;
                    $need -= $take;
                }
                $drawn[$index] = $got;
                $drawnUnits[$index] = ($drawnUnits[$index] ?? 0) + $pr['q'];
            }

            foreach ($drawn as $index => $byLine) {
                $pr = $packRules[$index];
                $allocations[] = [
                    'rule' => $pr['rule'], 'q' => $pr['q'], 'byLine' => $byLine,
                    'lines' => $pr['targets'], 'capped' => $pr['limit'] !== null,
                ];
            }
        }
        return $allocations;
    }

    /**
     * One discount per line, from every allocation covering it — packs.ts `buildDiscountCandidates`.
     *
     * A percentage, or money when any fixed rule is involved.
     *
     * @param string[] $activeKeys
     * @param array $experiments
     * @param Line[] $lines
     * @param Receipts $receipts
     * @param string[]|null $cartKeys every key on the cart, for stamps from another source
     * @return array<string, array{type: string, value: float, message: string}> keyed by line id
     */
    public function productCandidates(
        array $activeKeys,
        array $experiments,
        array $lines,
        Receipts $receipts,
        ?array $cartKeys = null
    ): array {
        // Order rules become a real order discount (orderCandidates), as with the per-variant Shopify discounts.
        $withoutOrderRules = [];
        foreach ($experiments as $key => $config) {
            if (is_array($config) && is_array($config['r'] ?? null)) {
                $config['r'] = array_values(array_filter(
                    $config['r'],
                    fn ($rule) => !$this->isOrderDiscountRule($rule)
                ));
            }
            $withoutOrderRules[$key] = $config;
        }

        $perLine = [];
        foreach ($this->allocatePacks($activeKeys, $withoutOrderRules, $lines, $receipts, $cartKeys) as $alloc) {
            $moneyByLine = $this->allocMoney($alloc);
            foreach ($alloc['lines'] as $line) {
                $fraction = $this->lineFraction($alloc, $line);
                $money = $moneyByLine[$line->id] ?? 0.0;
                if ($fraction <= 0 && $money <= 0) {
                    continue;
                }
                $entry = $perLine[$line->id] ?? ['fraction' => 0.0, 'money' => 0.0, 'best' => 0.0, 'message' => ''];
                $entry['fraction'] += $fraction;
                $entry['money'] += $money;
                // The biggest contributor names the line.
                $weight = $fraction > 0 ? $fraction : ($line->subtotal > 0 ? $money / $line->subtotal : 0);
                if ($weight > $entry['best']) {
                    $entry['best'] = $weight;
                    $entry['message'] = $this->message($alloc['rule']);
                }
                $perLine[$line->id] = $entry;
            }
        }

        $candidates = [];
        foreach ($lines as $line) {
            $entry = $perLine[$line->id] ?? null;
            if ($entry === null) {
                continue;
            }
            if ($entry['money'] > 0) {
                $total = min(
                    $this->roundCents($entry['money'] + $entry['fraction'] * $line->subtotal),
                    $line->subtotal
                );
                if ($total > 0) {
                    $candidates[$line->id] = ['type' => 'fixed', 'value' => $total, 'message' => $entry['message']];
                }
                continue;
            }
            $value = $this->clampPercent($entry['fraction'] * 100);
            if ($value > 0) {
                $candidates[$line->id] = ['type' => 'percent', 'value' => $value, 'message' => $entry['message']];
            }
        }
        return $candidates;
    }

    /**
     * One order discount per order rule, on the lines it is eligible for — packs.ts `buildOrderDiscountCandidates`.
     *
     * @param string[] $activeKeys
     * @param array $experiments
     * @param Line[] $lines
     * @param Receipts $receipts
     * @return array[] type, value, message, lineIds
     */
    public function orderCandidates(array $activeKeys, array $experiments, array $lines, Receipts $receipts): array
    {
        $candidates = [];
        foreach ($activeKeys as $key) {
            $config = $experiments[$key] ?? null;
            if (!is_array($config) || empty($config['on']) || !is_array($config['r'] ?? null)) {
                continue;
            }
            foreach ($config['r'] as $rule) {
                if (!$this->isOrderDiscountRule($rule) || !$this->hasPositiveValue($rule)) {
                    continue;
                }
                $targets = $this->resolveTargetLines($rule, $lines, $receipts);
                if (!$targets) {
                    continue;
                }
                $candidates[] = [
                    'type' => $rule['t'] === 'p' ? 'percent' : 'fixed',
                    'value' => $rule['t'] === 'p'
                        ? $this->clampPercent((float)$rule['v'])
                        : $this->roundCents((float)$rule['v']),
                    'message' => $this->message($rule),
                    'lineIds' => array_map(fn (Line $l) => $l->id, $targets),
                ];
            }
        }
        return $candidates;
    }

    /**
     * Money per line: each line's product discount, then the biggest order discount
     * (Shopify's "maximum" selection) on what the eligible lines have left, split to the cent.
     *
     * @param Line[] $lines
     * @param array $productCandidates
     * @param array[] $orderCandidates
     * @return array<string, array{amount: float, message: string}>
     */
    private function settle(array $lines, array $productCandidates, array $orderCandidates): array
    {
        $result = [];
        $left = [];
        foreach ($lines as $line) {
            $amount = 0.0;
            $candidate = $productCandidates[$line->id] ?? null;
            if ($candidate !== null) {
                $amount = $candidate['type'] === 'percent'
                    ? $this->roundCents($line->subtotal * $candidate['value'] / 100)
                    : $candidate['value'];
                $amount = min($amount, $line->available());
                if ($amount > 0) {
                    $result[$line->id] = [
                        'amount' => $amount,
                        'message' => $candidate['message'],
                        'messages' => $this->messages([$candidate['message']]),
                    ];
                }
            }
            $left[$line->id] = max(0.0, round($line->available() - $amount, 2));
        }

        $best = null;
        foreach ($orderCandidates as $candidate) {
            $pool = array_sum(array_map(fn ($id) => $left[$id] ?? 0.0, $candidate['lineIds']));
            $amount = $candidate['type'] === 'percent'
                ? $this->roundCents($pool * $candidate['value'] / 100)
                : min($candidate['value'], $pool);
            if ($amount > 0 && ($best === null || $amount > $best['amount'])) {
                $best = ['amount' => $amount, 'pool' => $pool] + $candidate;
            }
        }
        if ($best === null) {
            return $result;
        }

        // Split in whole cents by what each line has left (largest remainder), so the parts
        // add up to exactly the order discount.
        $totalCents = (int)round($best['amount'] * 100);
        $shares = [];
        $remainders = [];
        foreach ($best['lineIds'] as $id) {
            $exact = $best['pool'] > 0 ? $totalCents * ($left[$id] ?? 0) / $best['pool'] : 0;
            $shares[$id] = (int)floor($exact + 1e-9);
            $remainders[$id] = $exact - $shares[$id];
        }
        $missing = $totalCents - array_sum($shares);
        arsort($remainders);
        foreach (array_keys($remainders) as $id) {
            if ($missing <= 0) {
                break;
            }
            $shares[$id]++;
            $missing--;
        }
        foreach ($shares as $id => $cents) {
            if ($cents <= 0) {
                continue;
            }
            $previous = $result[$id] ?? ['amount' => 0.0, 'message' => '', 'messages' => []];
            $result[$id] = [
                'amount' => $this->roundCents($previous['amount'] + $cents / 100),
                'message' => $previous['message'] !== '' ? $previous['message'] : $best['message'],
                'messages' => $this->messages([...$previous['messages'], $best['message']]),
            ];
        }
        return $result;
    }

    /**
     * The lines a rule may discount — packs.ts `resolveTargetLines`.
     *
     * @param array $rule
     * @param Line[] $lines
     * @param Receipts $receipts
     * @return Line[]
     */
    private function resolveTargetLines(array $rule, array $lines, Receipts $receipts): array
    {
        $pt = $rule['pt'] ?? null;
        $eligible = $pt === 'o'
            ? array_filter($lines, fn (Line $l) => !$l->subscription)
            : ($pt === 's' ? array_filter($lines, fn (Line $l) => $l->subscription) : $lines);
        $eligible = array_values($eligible);
        $scope = $rule['s'] ?? null;
        if ($scope === 'o') {
            return $eligible;
        }
        $pool = !empty($rule['co'])
            ? array_values(array_filter($eligible, fn (Line $l) => $receipts->fromCheckoutCard($l)))
            : $eligible;

        // Recipe attribution: only a receipt whose MAC verifies over the line's real product counts.
        if ($scope === 'r') {
            $recipeId = is_string($rule['rid'] ?? null) ? $rule['rid'] : '';
            if ($recipeId === '') {
                return [];
            }
            if (!empty($rule['co'])) {
                return array_values(array_filter(
                    $pool,
                    fn (Line $l) => (Receipts::parse($l->recClaim)['recipeId'] ?? null) === $recipeId
                ));
            }
            return array_values(array_filter(
                $pool,
                fn (Line $l) => $l->recClaim !== null && $receipts->forRecipe($l, $recipeId)
            ));
        }

        $products = is_array($rule['p'] ?? null) ? $rule['p'] : [];
        $categories = is_array($rule['c'] ?? null) ? $rule['c'] : [];
        if (!$products && !$categories) {
            return [];
        }
        return array_values(array_filter($pool, fn (Line $l) => $l->matches($products, $categories)));
    }

    /**
     * How many units the rule's cart-value tier lets it discount — packs.ts `cartValueCap`.
     *
     * Null when it has none (or its reached tier is unlimited), 0 when no tier is reached.
     * "The rest of the cart" excludes the rule's own lines, so a free gift never pays for itself.
     *
     * @param array $rule
     * @param Line[] $lines
     * @param Line[] $targets
     * @return int|null
     */
    private function cartValueCap(array $rule, array $lines, array $targets): ?int
    {
        if (!is_array($rule['cv'] ?? null)) {
            return null;
        }
        $targeted = array_flip(array_map(fn (Line $l) => $l->id, $targets));
        $rest = 0.0;
        foreach ($lines as $line) {
            if (!isset($targeted[$line->id])) {
                $rest += $line->subtotal;
            }
        }
        $reached = null;
        $best = -1.0;
        foreach ($rule['cv'] as $tier) {
            if (!is_array($tier) || !is_numeric($tier[0] ?? null) || !is_numeric($tier[1] ?? null)) {
                continue;
            }
            $min = (float)$tier[0];
            $max = (int)floor((float)$tier[1]);
            if ($min <= 0 || $max < 0) {
                continue;
            }
            if ($rest >= $this->roundCents($min) && $min > $best) {
                $best = $min;
                $reached = $max;
            }
        }
        if ($reached === null) {
            return 0;
        }
        return $reached === 0 ? null : $reached;
    }

    /**
     * The quantity tier one pool's units reach — packs.ts `quantityTier`.
     *
     * One pack covering every unit at the tier's value; null when no tier above the base pack is
     * reached. A same-product rule asks once per product, so mixing products never unlocks one.
     * Malformed tiers are skipped, never read as reached.
     *
     * @param array $rule
     * @param Line[] $pool
     * @return array{q: int, v: float}|null
     */
    private function quantityTier(array $rule, array $pool): ?array
    {
        if (!is_array($rule['qt'] ?? null)) {
            return null;
        }
        $units = array_sum(array_map(fn (Line $l) => $this->lineQuantity($l), $pool));
        $reached = null;
        foreach ($rule['qt'] as $tier) {
            if (!is_array($tier) || !is_numeric($tier[0] ?? null) || !is_numeric($tier[1] ?? null)) {
                continue;
            }
            $min = (int)floor((float)$tier[0]);
            $value = (float)$tier[1];
            if ($min < 2 || $value <= 0) {
                continue;
            }
            if ($units >= $min && ($reached === null || $min > $reached['q'])) {
                $reached = ['q' => $min, 'v' => $value];
            }
        }
        return $reached === null ? null : ['q' => $units, 'v' => $reached['v']];
    }

    /**
     * Stamped for this key, or a covered stamp from another key — packs.ts `stampQualifies`.
     *
     * A covered stamp from another key on the cart counts while the cart also holds this product
     * stamped by this key: the product is in the cart from two sources that both discount it, so
     * the best value wins.
     *
     * @param Line $line
     * @param string $key
     * @param Line[] $lines
     * @param string[] $cartKeys
     * @param Receipts $receipts
     * @return bool
     */
    private function stampQualifies(Line $line, string $key, array $lines, array $cartKeys, Receipts $receipts): bool
    {
        if ($receipts->hasStamp($line, $key)) {
            return true;
        }
        if (!Receipts::isCoveredStamp($line->recClaim)) {
            return false;
        }
        $otherStamped = false;
        foreach ($cartKeys as $other) {
            if ($other !== $key && $receipts->hasStamp($line, (string)$other)) {
                $otherStamped = true;
                break;
            }
        }
        if (!$otherStamped) {
            return false;
        }
        foreach ($lines as $own) {
            if ($own->id !== $line->id && $own->productId === $line->productId && $receipts->hasStamp($own, $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The pools one rule draws a pack from, priciest line first — packs.ts `drawPools`.
     *
     * One pool for a pooled rule, one per product for a same-product rule.
     *
     * @param array $pr
     * @return Line[][]
     */
    private function drawPools(array $pr): array
    {
        $byPrice = $pr['targets'];
        usort($byPrice, fn (Line $a, Line $b) => $this->unitPrice($b) <=> $this->unitPrice($a));
        if (!$pr['sameProduct']) {
            return [$byPrice];
        }
        $groups = [];
        foreach ($byPrice as $line) {
            $groups[$line->productId][] = $line;
        }
        return array_values($groups);
    }

    /**
     * Checkout-card recipe rules, then card product rules, then the rest — packs.ts `cardRulesFirst`.
     *
     * @param array $rules
     * @return array
     */
    private function cardRulesFirst(array $rules): array
    {
        $hasCard = (bool)array_filter($rules, fn ($r) => is_array($r) && !empty($r['co']));
        if (!$hasCard) {
            return $rules;
        }
        $rank = fn ($r) => !is_array($r) || empty($r['co']) ? 2 : (($r['s'] ?? null) === 'r' ? 0 : 1);
        usort($rules, fn ($a, $b) => $rank($a) <=> $rank($b));
        return $rules;
    }

    /**
     * Units of the line the allocation covers: all of them for a whole-line allocation.
     *
     * @param array $alloc
     * @param Line $line
     * @return int
     */
    private function allocUnits(array $alloc, Line $line): int
    {
        $units = $alloc['q'] === 0 ? $this->lineQuantity($line) : ($alloc['byLine'][$line->id] ?? 0);
        return max(0, $units);
    }

    /**
     * Percentage rules: the share of the line this allocation covers.
     *
     * @param array $alloc
     * @param Line $line
     * @return float
     */
    private function lineFraction(array $alloc, Line $line): float
    {
        if (($alloc['rule']['t'] ?? null) !== 'p') {
            return 0.0;
        }
        $units = $this->allocUnits($alloc, $line);
        if ($units <= 0) {
            return 0.0;
        }
        $share = $alloc['q'] === 0 ? 1.0 : $units / max(1, $this->lineQuantity($line));
        return $this->clampPercent((float)$alloc['rule']['v']) / 100 * $share;
    }

    /**
     * Fixed rules' money per line — packs.ts `allocMoney`.
     *
     * One flat amount per line for a whole-line rule, else the allocation split by largest
     * remainder in whole cents.
     *
     * @param array $alloc
     * @return array<string, float>
     */
    private function allocMoney(array $alloc): array
    {
        $out = [];
        if (($alloc['rule']['t'] ?? null) !== 'f') {
            return $out;
        }
        $eligible = array_values(array_filter(
            $alloc['lines'],
            fn (Line $l) => $l->subtotal > 0 && $this->allocUnits($alloc, $l) > 0
        ));
        if (!$eligible) {
            return $out;
        }
        $perPack = $this->roundCents((float)$alloc['rule']['v']);
        if ($perPack <= 0) {
            return $out;
        }
        if ($alloc['q'] < 2 && !$alloc['capped']) {
            foreach ($eligible as $line) {
                $out[$line->id] = $perPack;
            }
            return $out;
        }

        $unitsPerLine = array_map(fn (Line $l) => $this->allocUnits($alloc, $l), $eligible);
        $totalUnits = array_sum($unitsPerLine);
        $totalCents = (int)round(round($perPack * 100) * ($totalUnits / max(1, $alloc['q'])));
        $floors = [];
        $order = [];
        foreach ($unitsPerLine as $i => $n) {
            $floors[$i] = intdiv($totalCents * $n, $totalUnits);
            $order[] = ['i' => $i, 'rem' => (($totalCents * $n) % $totalUnits) / $totalUnits];
        }
        $left = $totalCents - array_sum($floors);
        usort($order, fn ($a, $b) => ($b['rem'] <=> $a['rem']) ?: ($a['i'] <=> $b['i']));
        foreach ($order as $o) {
            if ($left <= 0) {
                break;
            }
            $floors[$o['i']]++;
            $left--;
        }
        foreach ($eligible as $i => $line) {
            if ($floors[$i] > 0) {
                $out[$line->id] = $floors[$i] / 100;
            }
        }
        return $out;
    }

    /**
     * Order rules that become a real order discount: pack-less by schema, not checkout-card deals.
     *
     * @param mixed $rule
     * @return bool
     */
    private function isOrderDiscountRule($rule): bool
    {
        return is_array($rule) && ($rule['s'] ?? null) === 'o' && empty($rule['co']);
    }

    /**
     * Whether the rule takes anything off: a percentage above 0, or a positive fixed amount.
     *
     * @param array $rule
     * @return bool
     */
    private function hasPositiveValue(array $rule): bool
    {
        $value = is_numeric($rule['v'] ?? null) ? (float)$rule['v'] : 0.0;
        if (($rule['t'] ?? null) === 'p') {
            return $this->clampPercent($value) > 0;
        }
        return ($rule['t'] ?? null) === 'f' && $value > 0;
    }

    /**
     * A pack of 1 is no pack, so only >= 2 counts.
     *
     * @param mixed $q
     * @return int|null
     */
    private function normalizePack($q): ?int
    {
        if (!is_numeric($q)) {
            return null;
        }
        $n = (int)floor((float)$q);
        return $n >= 2 ? $n : null;
    }

    /**
     * The line's quantity, never negative.
     *
     * @param Line $line
     * @return int
     */
    private function lineQuantity(Line $line): int
    {
        return max(0, $line->quantity);
    }

    /**
     * One unit's price on the line; 0 for an empty line.
     *
     * @param Line $line
     * @return float
     */
    private function unitPrice(Line $line): float
    {
        return $line->quantity > 0 ? $line->subtotal / $line->quantity : 0.0;
    }

    /**
     * Every offer that discounted a line, in order, without blanks or repeats.
     *
     * @param string[] $messages
     * @return string[]
     */
    private function messages(array $messages): array
    {
        return array_values(array_unique(array_filter($messages, fn ($m) => $m !== '')));
    }

    /**
     * The rule's message; empty when it has none, so the total reads just "Discount".
     *
     * @param array $rule
     * @return string
     */
    private function message(array $rule): string
    {
        return is_string($rule['m'] ?? null) ? trim($rule['m']) : '';
    }

    /**
     * A percentage held to 0–100; anything not finite counts as 0.
     *
     * @param float $value
     * @return float
     */
    private function clampPercent(float $value): float
    {
        if (!is_finite($value) || $value <= 0) {
            return 0.0;
        }
        return $value >= 100 ? 100.0 : $value;
    }

    /**
     * Money rounded to whole cents.
     *
     * @param float $value
     * @return float
     */
    private function roundCents(float $value): float
    {
        return round($value, 2);
    }
}
