<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Rejects anything but a Variant shop id (a UUID), so a pasted SDK key or URL never reaches the page.
 */
class ShopId extends Value
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && !preg_match(self::PATTERN, $value)) {
            throw new LocalizedException(
                __('The Variant Shop ID must look like 123e4567-e89b-12d3-a456-426614174000.')
            );
        }
        $this->setValue(strtolower($value));

        return parent::beforeSave();
    }
}
