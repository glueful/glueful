<?php

declare(strict_types=1);

namespace Glueful\Helpers;

/**
 * Enhanced Static File Detector
 *
 * Provides robust static file detection using multiple methods
 * to handle edge cases and improve performance.
 */
class StaticFileDetector
{
    private array $staticExtensions;
    private array $staticMimeTypes;
    private array $cache = [];
    private int $maxCacheSize = 1000;

    public function __construct(array $config = [])
    {
        $this->staticExtensions = $config['extensions'] ?? $this->getDefaultExtensions();
        $this->staticMimeTypes = $config['mime_types'] ?? $this->getDefaultMimeTypes();
    }

    /**
     * Enhanced static file detection using MIME types
     *
     * @param string $path Request path
     * @return bool Whether the file is static
     */
    public function isStaticFile(string $path): bool
    {
        // Check cache first
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        $isStatic = $this->performDetection($path);

        // Cache result (with size limit)
        if (count($this->cache) < $this->maxCacheSize) {
            $this->cache[$path] = $isStatic;
        }

        return $isStatic;
    }

    /**
     * Perform multi-method static file detection
     *
     * @param string $path Request path
     * @return bool Whether the file is static
     */
    private function performDetection(string $path): bool
    {
        // Method 1: Extension-based detection
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, $this->staticExtensions)) {
            return true;
        }

        // Method 2: Path pattern analysis
        if ($this->isStaticByPath($path)) {
            return true;
        }

        // Method 3: File system check with MIME type
        if ($this->existsAsStaticFile($path)) {
            return true;
        }

        return false;
    }

    /**
     * Check if path matches static file patterns
     *
     * @param string $path Request path
     * @return bool Whether path indicates static file
     */
    private function isStaticByPath(string $path): bool
    {
        $staticPathPatterns = [
            '/^\/assets\//',
            '/^\/static\//',
            '/^\/public\//',
            '/^\/dist\//',
            '/^\/build\//',
            '/^\/_next\//',     // Next.js
            '/^\/\.well-known\//' // Well-known URIs
        ];

        foreach ($staticPathPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file exists and has static MIME type
     *
     * @param string $path Request path
     * @return bool Whether file exists as static
     */
    private function existsAsStaticFile(string $path): bool
    {
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . $path;

        if (!file_exists($fullPath)) {
            return false;
        }

        $mimeType = mime_content_type($fullPath);

        foreach ($this->staticMimeTypes as $staticMime) {
            if (str_starts_with($mimeType, $staticMime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get default static file extensions
     *
     * @return array Default extensions
     */
    private function getDefaultExtensions(): array
    {
        return [
            // Web assets
            'css', 'js', 'map', 'json',
            // Images
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tiff',
            // Fonts
            'woff', 'woff2', 'ttf', 'eot', 'otf',
            // Media
            'mp4', 'webm', 'ogg', 'mp3', 'wav', 'flac',
            // Documents
            'pdf', 'txt', 'xml',
            // Archives
            'zip', 'tar', 'gz',
            // Other common web assets
            'manifest', 'webmanifest', 'robots'
        ];
    }

    /**
     * Get default static MIME types
     *
     * @return array Default MIME type patterns
     */
    private function getDefaultMimeTypes(): array
    {
        return [
            'text/css',
            'application/javascript',
            'text/javascript',
            'image/',
            'font/',
            'audio/',
            'video/',
            'application/font',
            'application/octet-stream' // For .woff files
        ];
    }

    /**
     * Clear detection cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
