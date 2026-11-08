<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Variant\Pricing\Api\PriceTestConfigInterface;

/**
 * Keeps every price test in one row of Magento's `flag` table, like the experiment discounts:
 * read on every totals collection, written only when Variant publishes.
 */
class PriceTestConfig implements PriceTestConfigInterface
{
    private const FLAG = 'variant_price_tests';
    /** An experiment's id, or a recommendation draft's (`AR…`), as pricing keys name them. */
    private const EXPERIMENT_ID = '/^(EXP[A-Z0-9]+|AR\d+)$/';

    /**
     * @param FlagManager $flags
     * @param FlagLock $lock
     */
    public function __construct(
        private readonly FlagManager $flags,
        private readonly FlagLock $lock
    ) {
    }

    /**
     * @inheritdoc
     */
    public function saveExperiment(string $experimentId, string $config): bool
    {
        $this->assertExperimentId($experimentId);
        $arms = json_decode($config, true);
        if (!is_array($arms)) {
            throw new LocalizedException(__('config must be a JSON object of experiment arms.'));
        }
        foreach ($arms as $key => $arm) {
            if (!str_starts_with((string)$key, $experimentId . ':') || !is_array($arm)
                || !is_array($arm['r'] ?? null)) {
                throw new LocalizedException(
                    __('Every arm key must start with "%1:" and hold {"on", "r": [rules]}.', $experimentId)
                );
            }
        }
        $this->lock->run(self::FLAG, function () use ($experimentId, $arms) {
            $this->flags->saveFlag(self::FLAG, $this->withoutExperiment($this->load(), $experimentId) + $arms);
        });
        return true;
    }

    /**
     * @inheritdoc
     */
    public function deleteExperiment(string $experimentId): bool
    {
        $this->assertExperimentId($experimentId);
        $this->lock->run(self::FLAG, function () use ($experimentId) {
            $this->flags->saveFlag(self::FLAG, $this->withoutExperiment($this->load(), $experimentId));
        });
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getAll(): string
    {
        return (string)json_encode((object)$this->load());
    }

    /**
     * Every stored arm, keyed "EXP…:variantId".
     *
     * @return array
     */
    public function load(): array
    {
        $data = $this->flags->getFlagData(self::FLAG);
        return is_array($data) ? $data : [];
    }

    /**
     * The stored arms minus every arm of this experiment.
     *
     * @param array $all
     * @param string $experimentId
     * @return array
     */
    private function withoutExperiment(array $all, string $experimentId): array
    {
        return array_filter(
            $all,
            fn ($key) => !str_starts_with((string)$key, $experimentId . ':'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Reject anything that is not a Variant experiment id (EXP…).
     *
     * @param string $experimentId
     * @throws LocalizedException
     */
    private function assertExperimentId(string $experimentId): void
    {
        if (!preg_match(self::EXPERIMENT_ID, $experimentId)) {
            throw new LocalizedException(__('"%1" is not a Variant experiment id.', $experimentId));
        }
    }
}
