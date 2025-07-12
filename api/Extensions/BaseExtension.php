<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\Interfaces\ExtensionInterface;

/**
 * Base Extensions Class
 *
 * Simplified base class for all API extensions. Provides only the essential
 * functionality that is actually used by the framework.
 *
 * Extensions must implement:
 * - initialize() - Setup logic run when extension is loaded
 * - getMetadata() - Return extension metadata including dependencies
 * - checkHealth() - Return health status for monitoring
 *
 * Optional features available via traits:
 * - ExtensionDocumentationTrait - For getScreenshots() and getChangelog()
 *
 * @package Glueful\Extensions
 */
abstract class BaseExtension implements ExtensionInterface
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
     * Get extension metadata
     *
     * Returns information about the extension for display in the admin UI
     * and for dependency tracking. Extensions should override this method
     * to provide custom metadata.
     *
     * This method follows the Glueful Extension Metadata Standard.
     * Primary: Load from manifest.json v2.0
     * Fallback: Generate from class reflection (for backward compatibility)
     *
     * @see https://docs.glueful.com/extensions/metadata-standard
     * @return array Extension metadata
     */
    public static function getMetadata(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $extensionPath = dirname($reflection->getFileName());

        // Primary: Load from manifest.json v2.0
        $manifestPath = $extensionPath . '/manifest.json';
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $manifest;
            }
        }

        // Fallback: Generate from class reflection (for backward compatibility)
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
            'manifestVersion' => '2.0',
            'id' => strtolower($shortName),
            'name' => $shortName,
            'displayName' => $shortName,
            'version' => $version,
            'description' => $description,
            'author' => $author,
            'main' => "./{$shortName}.php",
            'engines' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0'
            ],
            'dependencies' => [
                'composer' => [],
                'extensions' => []
            ],
            'provides' => [],
            'capabilities' => [],
            'assets' => []
        ];
    }

    /**
     * Check extension health
     *
     * Checks if the extension is functioning correctly. Extensions can
     * override this to perform custom health checks.
     *
     * @return array Health status
     */
    public static function checkHealth(): array
    {
        return [
            'status' => 'healthy',
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
}
