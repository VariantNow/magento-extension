<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Accepts a OneTrust category ID such as C0002: letters, digits, underscores and hyphens.
 */
class OneTrustCategory extends Value
{
    public const PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if (!preg_match(self::PATTERN, $value)) {
            throw new LocalizedException(
                __('The OneTrust category ID must look like C0002: letters, digits, "_" or "-".')
            );
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
