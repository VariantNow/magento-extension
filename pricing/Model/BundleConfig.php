<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Variant\Pricing\Api\BundleConfigInterface;

/**
 * Keeps the bundles in one row of Magento's `flag` table, like the experiment discounts: read
 * on every totals collection and every bundle add, written only when Variant publishes.
 */
class BundleConfig implements BundleConfigInterface
{
    private const FLAG = 'variant_bundles';
    private const BUNDLE_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param FlagManager $flags
     */
    public function __construct(
        private readonly FlagManager $flags
    ) {
    }

    /**
     * @inheritdoc
     */
    public function save(string $config): bool
    {
        $data = json_decode($config, true);
        if (!is_array($data) || !is_array($data['bundles'] ?? null)) {
            throw new LocalizedException(__('config must be JSON like {"bundles": {…}}.'));
        }
        foreach ($data['bundles'] as $id => $bundle) {
            if (!preg_match(self::BUNDLE_ID, (string)$id)) {
                throw new LocalizedException(__('"%1" is not a Variant bundle id.', $id));
            }
            if (!is_array($bundle) || !in_array($bundle['t'] ?? null, ['f', 'p'], true)
                || !is_numeric($bundle['v'] ?? null)) {
                throw new LocalizedException(__('Bundle "%1" needs "t" ("f" or "p") and a numeric "v".', $id));
            }
        }
        $this->flags->saveFlag(self::FLAG, ['bundles' => $data['bundles']]);
        return true;
    }

    /**
     * @inheritdoc
     */
    public function get(): string
    {
        return (string)json_encode(['bundles' => (object)($this->load()['bundles'] ?? [])]);
    }

    /**
     * The stored config, `bundles` keyed by bundle id.
     *
     * @return array
     */
    public function load(): array
    {
        $data = $this->flags->getFlagData(self::FLAG);
        return is_array($data) ? $data : [];
    }

    /**
     * One bundle's entry, or null when Variant has not published it.
     *
     * @param string $bundleId
     * @return array|null
     */
    public function bundle(string $bundleId): ?array
    {
        $bundle = $this->load()['bundles'][$bundleId] ?? null;
        return is_array($bundle) ? $bundle : null;
    }
}
