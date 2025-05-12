<?php

declare(strict_types=1);

namespace Glueful\ImageProcessing;

use RuntimeException;

/**
 * File-based Cache Implementation
 *
 * Handles image caching using filesystem storage.
 */
final class FileCache implements CacheInterface
{
    /**
     * Constructor
     *
     * @param string $directory Cache directory path
     * @throws RuntimeException If directory cannot be created
     */
    public function __construct(
        private readonly string $directory
    ) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException("Cannot create cache directory");
        }
    }

    public function get(string $key): ?string
    {
        $file = $this->getFilePath($key);
        return is_readable($file) ? file_get_contents($file) : null;
    }

    public function set(string $key, string $data): bool
    {
        return file_put_contents($this->getFilePath($key), $data) !== false;
    }

    public function clean(): void
    {
        $files = glob($this->directory . '/*');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file) && $now - filemtime($file) >= 86400) {
                @unlink($file);
            }
        }
    }

    private function getFilePath(string $key): string
    {
        return $this->directory . '/' . $key . '.cache';
    }
}
