<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Serializes the read-modify-write of one of Variant's config flags. Every experiment's arms
 * live in one flag row, so two publishes at once would each read the old map and the second
 * save would drop the first one's experiment; a database lock makes them take turns.
 */
class FlagLock
{
    private const WAIT_SECONDS = 10;

    /**
     * @param ResourceConnection $resources
     */
    public function __construct(
        private readonly ResourceConnection $resources
    ) {
    }

    /**
     * Run $update while holding the lock named after the flag.
     *
     * @param string $flag
     * @param callable $update
     * @return mixed what $update returns
     * @throws LocalizedException when another publish holds the lock for too long
     */
    public function run(string $flag, callable $update)
    {
        $connection = $this->resources->getConnection();
        $name = 'variant:' . $flag;
        if ((int)$connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, self::WAIT_SECONDS]) !== 1) {
            throw new LocalizedException(__('Another Variant publish is in progress. Try again.'));
        }
        try {
            return $update();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
