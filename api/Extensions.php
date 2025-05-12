<?php

declare(strict_types=1);

namespace Glueful;

/**
 * Base Extensions Class
 *
 * Abstract base class for all API extensions. Provides common functionality
 * and defines the extension lifecycle methods.
 *
 * Extensions can implement:
 * - initialize() - Setup logic run when extension is loaded
 * - registerServices() - Register extension services with the service container
 * - registerMiddleware() - Register extension middleware with the middleware pipeline
 * - getMetadata() - Return extension metadata including dependencies
 *
 * @package Glueful
 */
abstract class Extensions implements IExtensions
{
    /**
     * Initialize extension
     *
     * This method is called by ExtensionsManager when loading extensions.
     * Extensions should override this method to perform setup tasks.
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Override in child classes
    }

    /**
     * Register extension-provided services
     *
     * This method is called by ExtensionsManager when loading extensions.
     * Extensions should override this method to register their services
     * with the application's service container.
     *
     * @return void
     */
    public static function registerServices(): void
    {
        // Override in child classes
    }

    /**
     * Register extension-provided middleware
     *
     * This method is called by ExtensionsManager when loading extensions.
     * Extensions should override this method to register middleware
     * with the application's middleware pipeline.
     *
     * @return void
     */
    public static function registerMiddleware(): void
    {
        // Override in child classes
    }

    /**
     * Process extension request
     *
     * Main request handler for extension endpoints.
     * Should be overridden by child classes to implement specific logic.
     *
     * @param array $getParams Query parameters
     * @param array $postParams Post data
     * @return array Extension response
     */
    public static function process(array $getParams, array $postParams): array
    {
        return [];
    }

    /**
     * Get extension metadata
     *
     * Returns information about the extension for display in the admin UI
     * and for dependency tracking. Extensions should override this method
     * to provide custom metadata.
     *
     * This method follows the Glueful Extension Metadata Standard.
     * Required fields:
     * - name: Display name of the extension
     * - description: Brief description of what the extension does
     * - version: Semantic version (e.g. "1.0.0")
     * - author: Author name or organization
     * - requires: Object containing dependency requirements
     *
     * Optional fields include:
     * - homepage, documentation, license, keywords, category,
     * - screenshots, features, compatibility, settings, etc.
     *
     * @see https://docs.glueful.com/extensions/metadata-standard
     * @return array Extension metadata
     */
    public static function getMetadata(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $shortName = $reflection->getShortName();
        $docComment = $reflection->getDocComment();

        $description = '';
        $version = '1.0.0';
        $author = '';

        // Extract metadata from docblock if available
        if ($docComment) {
            if (preg_match('/@description\s+(.*)\r?\n/m', $docComment, $matches)) {
                $description = trim($matches[1]);
            }

            if (preg_match('/@version\s+(.*)\r?\n/m', $docComment, $matches)) {
                $version = trim($matches[1]);
            }

            if (preg_match('/@author\s+(.*)\r?\n/m', $docComment, $matches)) {
                $author = trim($matches[1]);
            }
        }

        return [
            'name' => $shortName,
            'description' => $description,
            'version' => $version,
            'author' => $author,
            'type' => 'optional', //core or optional
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => [] // List of required extensions
            ]
        ];
    }

    /**
     * Get extension dependencies
     *
     * Returns a list of other extensions this extension depends on.
     *
     * @return array List of extension dependencies
     */
    public static function getDependencies(): array
    {
        $metadata = static::getMetadata();
        return $metadata['requires']['extensions'] ?? [];
    }

    /**
     * Check environment-specific configuration
     *
     * Determines if the extension should be enabled in the current environment.
     * Extensions can override this for custom environment behavior.
     *
     * @param string $environment Current environment (dev, staging, production)
     * @return bool Whether the extension should be enabled in this environment
     */
    public static function isEnabledForEnvironment(string $environment): bool
    {
        // Default implementation always returns true
        // Extensions can override this for environment-specific behavior
        return true;
    }

    /**
     * Validate extension health
     *
     * Checks if the extension is functioning correctly. Extensions can
     * override this to perform custom health checks.
     *
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        return [
            'healthy' => true,
            'issues' => [],
            'metrics' => [
                'memory_usage' => 0,
                'execution_time' => 0,
                'database_queries' => 0,
                'cache_usage' => 0
            ]
        ];
    }

    /**
     * Get extension resource usage
     *
     * Returns information about resources used by this extension.
     * Extensions should override this to provide accurate resource metrics.
     *
     * @return array Resource usage metrics
     */
    public static function getResourceUsage(): array
    {
        // Default implementation returns empty metrics
        // Extensions should override this to provide accurate metrics
        return [
            'memory_usage' => 0, // bytes
            'execution_time' => 0, // milliseconds
            'database_queries' => 0, // count
            'cache_usage' => 0 // bytes
        ];
    }

    /**
     * Get extension screenshots
     *
     * Returns an array of screenshots for the extension.
     * By default, looks for images in the 'screenshots' directory.
     *
     * @return array Screenshots information
     */
    public static function getScreenshots(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $extensionDir = dirname($reflection->getFileName());
        $screenshotsDir = $extensionDir . '/screenshots';

        if (!is_dir($screenshotsDir)) {
            return [];
        }

        $screenshots = [];
        $files = glob($screenshotsDir . '/*.{png,jpg,jpeg,gif}', GLOB_BRACE);

        foreach ($files as $file) {
            if (strpos(basename($file), 'thumb') !== false) {
                continue; // Skip thumbnails
            }

            $filename = basename($file);
            $replacements = ['-', '_', '.png', '.jpg', '.jpeg', '.gif'];
            $with = [' ', ' ', '', '', '', ''];
            $title = ucfirst(str_replace($replacements, $with, $filename));

            // Check for thumbnail
            $thumbName = str_replace(
                ['png', 'jpg', 'jpeg', 'gif'],
                ['thumb.png', 'thumb.jpg', 'thumb.jpeg', 'thumb.gif'],
                $file
            );
            $thumbnail = null;

            if (file_exists($thumbName)) {
                $thumbnail = 'screenshots/' . basename($thumbName);
            }

            $screenshots[] = [
                'title' => $title,
                'description' => '',
                'url' => 'screenshots/' . $filename,
                'thumbnail' => $thumbnail
            ];
        }

        return $screenshots;
    }

    /**
     * Get extension changelog
     *
     * Returns the changelog information for this extension.
     * By default, attempts to parse a CHANGELOG.md file if present.
     *
     * @return array Version history with changes
     */
    public static function getChangelog(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $extensionDir = dirname($reflection->getFileName());
        $changelogFile = $extensionDir . '/CHANGELOG.md';

        if (!file_exists($changelogFile)) {
            return [];
        }

        $content = file_get_contents($changelogFile);
        if (!$content) {
            return [];
        }

        $changelog = [];
        $currentVersion = null;
        $currentDate = null;
        $currentChanges = [];

        // Simple markdown parsing
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            // Match version headers (## 1.0.0 - 2023-01-15)
            if (preg_match('/^##\s+([0-9.]+)\s*(?:-\s*([0-9]{4}-[0-9]{2}-[0-9]{2}))?/', $line, $matches)) {
                // Save previous version if exists
                if ($currentVersion) {
                    $changelog[] = [
                        'version' => $currentVersion,
                        'date' => $currentDate,
                        'changes' => $currentChanges
                    ];
                }

                // Start new version
                $currentVersion = $matches[1];
                $currentDate = $matches[2] ?? null;
                $currentChanges = [];
            } elseif ($currentVersion && preg_match('/^\s*[*-]\s+(.+)$/', $line, $matches)) {
                $currentChanges[] = trim($matches[1]);
            }
        }

        // Add the last version
        if ($currentVersion) {
            $changelog[] = [
                'version' => $currentVersion,
                'date' => $currentDate,
                'changes' => $currentChanges
            ];
        }

        return $changelog;
    }
}
