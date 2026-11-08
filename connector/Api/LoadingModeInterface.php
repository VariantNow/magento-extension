<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Api;

/**
 * How the storefront loads Variant's runtime tags, set by Variant's backend through the
 * integration token when the shop's "Script loading" setting changes — the Magento counterpart
 * of the `variant.loading_mode` metafield Shopify's app embed reads.
 * @api
 */
interface LoadingModeInterface
{
    /**
     * Set the mode for every store view.
     *
     * @param string $mode "blocking" or "async"
     * @return bool
     */
    public function save(string $mode): bool;

    /**
     * The mode the default scope holds.
     *
     * @return string
     */
    public function get(): string;
}
