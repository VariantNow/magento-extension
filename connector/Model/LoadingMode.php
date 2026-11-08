<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Variant\Connector\Api\LoadingModeInterface;

/**
 * Keeps the loading mode in core config at the default scope, where Config reads it.
 *
 * The runtime tags are in every cached page, so a change refreshes the config and full page
 * caches the way System > Cache Management does: the event lets Varnish purge as well. Saving
 * the mode the store already has changes nothing and clears nothing.
 */
class LoadingMode implements LoadingModeInterface
{
    private const MODES = [Config::LOADING_MODE_BLOCKING, Config::LOADING_MODE_ASYNC];
    private const CACHE_TYPES = ['config', 'full_page'];

    /**
     * @param WriterInterface $writer
     * @param ScopeConfigInterface $scopeConfig
     * @param ReinitableConfigInterface $reinitableConfig
     * @param TypeListInterface $cacheTypes
     * @param ManagerInterface $events
     */
    public function __construct(
        private readonly WriterInterface $writer,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypes,
        private readonly ManagerInterface $events
    ) {
    }

    /**
     * @inheritdoc
     */
    public function save(string $mode): bool
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new LocalizedException(__('mode must be "blocking" or "async".'));
        }
        if ($mode === $this->get()) {
            return true;
        }
        $this->writer->save(Config::XML_PATH_LOADING_MODE, $mode);
        $this->reinitableConfig->reinit();
        foreach (self::CACHE_TYPES as $type) {
            $this->cacheTypes->cleanType($type);
            $this->events->dispatch('adminhtml_cache_refresh_type', ['type' => $type]);
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function get(): string
    {
        $mode = (string)$this->scopeConfig->getValue(Config::XML_PATH_LOADING_MODE);
        return $mode === Config::LOADING_MODE_ASYNC ? $mode : Config::LOADING_MODE_BLOCKING;
    }
}
