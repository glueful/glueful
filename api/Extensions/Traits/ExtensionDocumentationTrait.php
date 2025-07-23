<?php

declare(strict_types=1);

namespace Glueful\Extensions\Traits;

/**
 * Extension Documentation Trait
 *
 * Provides documentation and metadata methods for extensions
 */
trait ExtensionDocumentationTrait
{
    /**
     * Get extension documentation
     *
     * @return array Documentation array
     */
    public static function getDocumentation(): array
    {
        return [
            'description' => static::getDescription(),
            'version' => static::getVersion(),
            'author' => static::getAuthor(),
            'features' => static::getFeatures(),
            'configuration' => static::getConfigurationOptions(),
            'api_endpoints' => static::getApiEndpoints(),
            'permissions' => static::getRequiredPermissions(),
        ];
    }

    /**
     * Get extension description
     *
     * @return string
     */
    public static function getDescription(): string
    {
        $metadata = static::getMetadata();
        return $metadata['description'] ?? 'No description available';
    }

    /**
     * Get extension version
     *
     * @return string
     */
    public static function getVersion(): string
    {
        $metadata = static::getMetadata();
        return $metadata['version'] ?? '1.0.0';
    }

    /**
     * Get extension author
     *
     * @return string
     */
    public static function getAuthor(): string
    {
        $metadata = static::getMetadata();
        return $metadata['author'] ?? 'Unknown';
    }

    /**
     * Get extension features
     *
     * @return array
     */
    public static function getFeatures(): array
    {
        // Override in extension class to provide specific features
        return [];
    }

    /**
     * Get configuration options
     *
     * @return array
     */
    public static function getConfigurationOptions(): array
    {
        // Override in extension class to provide configuration schema
        return [];
    }

    /**
     * Get API endpoints provided by this extension
     *
     * @return array
     */
    public static function getApiEndpoints(): array
    {
        // Override in extension class to list API endpoints
        return [];
    }

    /**
     * Get required permissions
     *
     * @return array
     */
    public static function getRequiredPermissions(): array
    {
        // Override in extension class to list required permissions
        return [];
    }

    /**
     * Check if extension has documentation
     *
     * @return bool
     */
    public static function hasDocumentation(): bool
    {
        return !empty(static::getFeatures()) || !empty(static::getApiEndpoints());
    }
}
