<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model\Discount;

/**
 * Recommendation and checkout-card receipts — MIRROR of the Shopify discount function's
 * rec-token.ts. The receipt on a cart item is a claim written by the storefront and never
 * trusted alone: what decides eligibility is a MAC recomputed over the item's real product
 * id and the rule's recipe id, keyed with secrets only Variant's backend and this store hold.
 *
 * Message: "v1|<first 8 chars of recipe id>|<product id>", "v1c|…" for a checkout card.
 */
class Receipts
{
    private const RECIPE_ID_MAC_CHARS = 8;

    /** @var array<string, bool> verification results for one calculation */
    private array $verified = [];

    /**
     * @param SipHash $sipHash
     * @param string[] $keys receipt signing keys by key id
     */
    public function __construct(
        private readonly SipHash $sipHash,
        private readonly array $keys
    ) {
    }

    /**
     * `recipeId.keyId.mac`, or `recipeId.keyId.mac.c` for a checkout card's receipt.
     *
     * @param string|null $raw
     * @return array{recipeId: string, keyId: string, mac: string, checkout: bool}|null
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure string parser, no state or dependencies to intercept
    public static function parse(?string $raw): ?array
    {
        if (!$raw) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 3 && !(count($parts) === 4 && $parts[3] === 'c')) {
            return null;
        }
        [$recipeId, $keyId, $mac] = $parts;
        if ($recipeId === '' || $keyId === '' || $mac === '') {
            return null;
        }
        return ['recipeId' => $recipeId, 'keyId' => $keyId, 'mac' => $mac, 'checkout' => count($parts) === 4];
    }

    /**
     * Whether the line carries a valid receipt for this recipe on its own product.
     *
     * @param Line $line
     * @param string $recipeId
     * @return bool
     */
    public function forRecipe(Line $line, string $recipeId): bool
    {
        $cacheKey = $line->id . '|' . $recipeId;
        if (!isset($this->verified[$cacheKey])) {
            $claim = self::parse($line->recClaim);
            $this->verified[$cacheKey] = $claim !== null && $this->verify($claim, $recipeId, $line->productId);
        }
        return $this->verified[$cacheKey];
    }

    /**
     * Whether a checkout card added this line (its receipt is a verified checkout receipt).
     *
     * @param Line $line
     * @return bool
     */
    public function fromCheckoutCard(Line $line): bool
    {
        $cacheKey = $line->id . '|checkout';
        if (!isset($this->verified[$cacheKey])) {
            $claim = self::parse($line->recClaim);
            $this->verified[$cacheKey] = $claim !== null && $claim['checkout']
                && $this->verify($claim, $claim['recipeId'], $line->productId);
        }
        return $this->verified[$cacheKey];
    }

    /**
     * Whether the line carries a valid discount stamp for this key — rec-token.ts `verifyDiscountStamp`.
     *
     * A stamp proves the item was added through a product form the storefront script stamped
     * for this discount key: `s.<keyId>.<mac>` over "v1s|<discount key>|<product id>", or
     * `sc.…` over "v1sc|…" when the stamping source also discounts the product (covered).
     * It rides in the same item field as recipe receipts.
     *
     * @param Line $line
     * @param string $discountKey
     * @return bool
     */
    public function hasStamp(Line $line, string $discountKey): bool
    {
        $cacheKey = $line->id . '|stamp|' . $discountKey;
        if (!isset($this->verified[$cacheKey])) {
            $this->verified[$cacheKey] = $this->verifyStamp($line->recClaim, $discountKey, $line->productId);
        }
        return $this->verified[$cacheKey];
    }

    /**
     * A covered stamp (`sc.`) — only these let another source's discount reach the line.
     *
     * @param string|null $raw
     * @return bool
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure string check, no state or dependencies to intercept
    public static function isCoveredStamp(?string $raw): bool
    {
        return $raw !== null && strncmp($raw, 'sc.', 3) === 0;
    }

    /**
     * Recompute a stamp's MAC with its key over this discount key and product.
     *
     * @param string|null $raw
     * @param string $discountKey
     * @param string $productId
     * @return bool
     */
    private function verifyStamp(?string $raw, string $discountKey, string $productId): bool
    {
        if (!$raw || !ctype_digit($productId)) {
            return false;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 3) {
            return false;
        }
        $scope = $parts[0] === 's' ? 'v1s' : ($parts[0] === 'sc' ? 'v1sc' : null);
        $key = $this->keys[$parts[1]] ?? null;
        if ($scope === null || !is_string($key)) {
            return false;
        }
        $expected = $this->sipHash->hex($key, $scope . '|' . $discountKey . '|' . $productId);
        return $expected !== null && hash_equals($expected, $parts[2]);
    }

    /**
     * Whether the claim's MAC matches one recomputed, with its key, over this recipe and product.
     *
     * @param array $claim
     * @param string $recipeId
     * @param string $productId
     * @return bool
     */
    private function verify(array $claim, string $recipeId, string $productId): bool
    {
        if ($claim['recipeId'] !== $recipeId || !ctype_digit($productId)) {
            return false;
        }
        $key = $this->keys[$claim['keyId']] ?? null;
        if (!is_string($key)) {
            return false;
        }
        $expected = $this->sipHash->hex(
            $key,
            ($claim['checkout'] ? 'v1c' : 'v1')
                . '|' . substr($recipeId, 0, self::RECIPE_ID_MAC_CHARS)
                . '|' . $productId
        );
        return $expected !== null && hash_equals($expected, $claim['mac']);
    }
}
