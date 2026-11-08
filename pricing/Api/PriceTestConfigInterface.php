<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Api;

/**
 * Variant's price tests, as Variant's backend publishes them through the integration token:
 * the Magento counterpart of the Shopify Cart Transform's config metafield. One experiment at
 * a time, so publishing a test never touches another.
 * @api
 */
interface PriceTestConfigInterface
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
     * Remove one experiment's arms, ending its price test.
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
}
