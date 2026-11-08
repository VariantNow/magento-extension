<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model;

use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * The tag on a cart item added as part of a Variant bundle — Magento's counterpart of Shopify's
 * `_variant_bundle_*` line properties. It is a custom item option, which does two jobs: the
 * bundle discount reads it, and Magento never merges two items whose options differ, so each
 * bundle add keeps its own items, apart from the same product added on its own.
 */
class BundleTag
{
    public const OPTION = 'variant_bundle';

    /**
     * The option value for one item of one bundle add.
     *
     * @param string $bundleId
     * @param string $instanceId
     * @param string $name
     * @param int $position
     * @return string
     */
    public function encode(string $bundleId, string $instanceId, string $name, int $position): string
    {
        return (string)json_encode(['b' => $bundleId, 'i' => $instanceId, 'n' => $name, 'p' => $position]);
    }

    /**
     * The item's bundle and bundle add, or null when it was not added as part of a bundle.
     *
     * @param AbstractItem $item
     * @return array{bundleId: string, instanceId: string}|null
     */
    public function read(AbstractItem $item): ?array
    {
        $option = $item->getOptionByCode(self::OPTION);
        $data = $option ? json_decode((string)$option->getValue(), true) : null;
        if (!is_array($data) || !is_string($data['b'] ?? null) || !is_string($data['i'] ?? null)) {
            return null;
        }
        return ['bundleId' => $data['b'], 'instanceId' => $data['i']];
    }
}
