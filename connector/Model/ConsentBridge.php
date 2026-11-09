<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * The consent bridge (view/frontend/web/js/consent-bridge.js), inlined ahead of Variant's tags.
 *
 * Read once per request and without its leading doc comment, which explains it to developers
 * and would only add bytes to every page. A missing or unreadable file renders nothing, so the
 * runtime behaves as it did before the bridge existed rather than breaking the page.
 */
class ConsentBridge
{
    private const FILE = '/view/frontend/web/js/consent-bridge.js';

    /**
     * @var string|null
     */
    private ?string $script = null;

    /**
     * @param ComponentRegistrarInterface $registrar
     * @param File $file
     */
    public function __construct(
        private readonly ComponentRegistrarInterface $registrar,
        private readonly File $file
    ) {
    }

    /**
     * The script to inline, or '' when it cannot be read.
     *
     * @return string
     */
    public function script(): string
    {
        if ($this->script === null) {
            $this->script = $this->read();
        }
        return $this->script;
    }

    /**
     * Read the file and drop its leading doc comment.
     *
     * @return string
     */
    private function read(): string
    {
        $dir = $this->registrar->getPath(ComponentRegistrar::MODULE, 'Variant_Connector');
        if ($dir === null) {
            return '';
        }
        try {
            $source = $this->file->fileGetContents($dir . self::FILE);
        } catch (FileSystemException $e) {
            return '';
        }
        return trim((string)preg_replace('#^\s*/\*\*.*?\*/#s', '', $source, 1));
    }
}
