<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Accepts a bare https origin; plain http only for local development hosts.
 */
class FilesHost extends Value
{
    private const PATTERN = '#^(https://[a-z0-9.-]+'
        . '|http://(localhost|127\.0\.0\.1|[a-z0-9.-]+\.(localhost|test)))(:\d+)?$#i';

    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        $value = rtrim(trim((string)$this->getValue()), '/');
        if (!preg_match(self::PATTERN, $value)) {
            throw new LocalizedException(
                __('The Variant Files Host must be an https origin such as https://edge.variantnow.com.')
            );
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
