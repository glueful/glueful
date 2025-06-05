<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive File Information
 *
 * Contains metadata about an archived file including
 * path, size, and integrity checksum.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ArchiveFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly string $checksum
    ) {
    }

    /**
     * Get the filename from the path
     */
    public function getFilename(): string
    {
        return basename($this->path);
    }

    /**
     * Get human-readable file size
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Check if file exists
     */
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * Verify file integrity
     */
    public function verifyChecksum(): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $currentChecksum = hash('sha256', file_get_contents($this->path));
        return $currentChecksum === $this->checksum;
    }
}
