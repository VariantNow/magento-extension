<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Api;

/**
 * Variant's discount experiments, as Variant's backend publishes them through the
 * integration token. One experiment at a time, so publishing a test never touches another.
 * @api
 */
interface DiscountConfigInterface
{
    /**
     * Replace one experiment's arms.
     *
     * @param string $experimentId e.g. EXP7ECAB76E
     * @param string $config JSON: {"EXP7ECAB76E:<variantId>": {"on": true, "r": [rules]}, …}
     * @return bool
     */
    public function saveExperiment(string $experimentId, string $config): bool;

    /**
     * Remove one experiment's arms, ending its discounts.
     *
     * @param string $experimentId
     * @return bool
     */
    public function deleteExperiment(string $experimentId): bool;

    /**
     * Every arm currently stored, as JSON.
     *
     * @return string
     */
    public function getAll(): string;

    /**
     * Replace the keys that verify recommendation and checkout-card receipts.
     *
     * The Magento counterpart of the Shopify discount's `sig` metafield. Never shown to shoppers.
     *
     * @param string $keys JSON: {"v": 1, "k": {"<keyId>": "<32 hex chars>"}}
     * @return bool
     */
    public function saveReceiptKeys(string $keys): bool;
}
