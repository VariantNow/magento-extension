<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store-scoped Variant settings and the two runtime URLs derived from them.
 */
class Config
{
    public const XML_PATH_ENABLED = 'variant/general/enabled';
    public const XML_PATH_SHOP_ID = 'variant/general/shop_id';
    public const XML_PATH_FILES_HOST = 'variant/advanced/files_host';
    public const XML_PATH_LOADING_MODE = 'variant/general/loading_mode';
    public const LOADING_MODE_BLOCKING = 'blocking';
    public const LOADING_MODE_ASYNC = 'async';

    /**
     * Variant's files-only CDNs, which serve the engine under /sdk/v1/.
     */
    private const PRODUCTION_FILES_HOSTS = ['https://edge.variantnow.com', 'https://cdn.variantnow.com'];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the runtime should load: switched on and given a shop to load.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)
            && $this->getShopId($storeId) !== '';
    }

    /**
     * The Variant shop this store view reports to.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getShopId(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_SHOP_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Whether the runtime tags load async.
     *
     * The shop's "Script loading" setting, which Variant writes through LoadingModeInterface.
     * Missing or anything else means blocking.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAsync(?int $storeId = null): bool
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_LOADING_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) === self::LOADING_MODE_ASYNC;
    }

    /**
     * The shop's published config object (redirect tests, SDK key, loading mode).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getShopConfigUrl(?int $storeId = null): string
    {
        return $this->getFilesHost($storeId) . '/sdk/shops/' . rawurlencode($this->getShopId($storeId)) . '.js';
    }

    /**
     * The engine, identical for every shop. It loads the runner and the pixel itself.
     *
     * @return string
     */
    public function getEngineUrl(): string
    {
        // A development SDK serves the engine at its root, as on a Shopify dev store.
        return $this->getDevSdkHost() !== null
            ? $this->getFilesHost() . '/variant.js'
            : $this->getFilesHost() . '/sdk/v1/variant.js';
    }

    /**
     * The development SDK the files host points at, or null for Variant's production CDNs.
     *
     * On a production CDN the engine loads its runner from production. A development host serves
     * the runner too, so the engine must be told to load it from there: the counterpart of the
     * hosts a Shopify dev session writes into the store's loader.
     *
     * @return string|null
     */
    public function getDevSdkHost(): ?string
    {
        $host = $this->getFilesHost();
        return $host === '' || in_array($host, self::PRODUCTION_FILES_HOSTS, true) ? null : $host;
    }

    /**
     * Where variant.js and the shop config are served from, without a trailing slash.
     *
     * Read at store scope like the other settings, the current store's when none is given.
     *
     * @param int|null $storeId
     * @return string
     */
    private function getFilesHost(?int $storeId = null): string
    {
        return rtrim(
            (string)$this->scopeConfig->getValue(self::XML_PATH_FILES_HOST, ScopeInterface::SCOPE_STORE, $storeId),
            '/'
        );
    }
}
