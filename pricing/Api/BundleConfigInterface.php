<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Api;

/**
 * The shop's Variant bundles, as Variant's backend publishes them through the integration
 * token: the whole set at once, as Shopify's bundle discount metafield holds it.
 * @api
 */
interface BundleConfigInterface
{
    /**
     * Replace every bundle.
     *
     * @param string $config JSON: {"bundles": {"<bundleId>": {"t": "f"|"p", "v": 99.5, "n": "Name", "i": {…}}}}
     * @return bool
     */
    public function save(string $config): bool;

    /**
     * The stored bundles, as JSON.
     *
     * @return string
     */
    public function get(): string;
}
