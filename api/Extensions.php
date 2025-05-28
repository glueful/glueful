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
     * Get the extension's service provider
     *
     * Returns the service provider instance for this extension.
     * All extensions MUST override this method to register their services.
     *
     * @return \Glueful\DI\Interfaces\ServiceProviderInterface
     */
    abstract public static function getServiceProvider(): \Glueful\DI\Interfaces\ServiceProviderInterface;

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
     * Get middleware priority
     *
     * Returns the priority level for this extension's middleware.
     * Lower numbers = higher priority (executed first).
     * Range: 1-1000, default is 100.
     *
     * @return int Middleware priority (1=highest, 1000=lowest)
     */
    public static function getMiddlewarePriority(): int
    {
        return 100;
    }

    /**
     * Get event listeners
     *
     * Returns an array of event listeners that this extension provides.
     * Each listener should be a callable or class method reference.
     *
     * @return array<string, callable|string> Event name => listener mapping
     */
    public static function getEventListeners(): array
    {
        return [];
    }

    /**
     * Get event subscribers
     *
     * Returns an array of events this extension wants to subscribe to.
     * Allows extensions to listen to system and other extension events.
     *
     * @return array<string, array> Event name => [method, priority] mapping
     */
    public static function getEventSubscribers(): array
    {
        return [];
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
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
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
     * Validate extension security
     *
     * Returns security requirements and validation for this extension.
     * Used by the security manager to ensure safe extension operation.
     *
     * @return array Security configuration and requirements
     */
    public static function validateSecurity(): array
    {
        return [
            'permissions' => [], // Required permissions array
            'sandbox' => false,  // Whether extension should run in sandbox
            'signature' => '',   // Code signature for verification
            'trusted' => false,  // Whether extension is from trusted source
            'network_access' => false, // Whether extension needs network access
            'file_access' => [],       // Required file access paths
            'database_access' => false, // Whether extension needs database access
            'admin_only' => false,     // Whether extension requires admin privileges
        ];
    }

    /**
     * Get required permissions
     *
     * Returns an array of permissions this extension requires to function.
     * Used by the permission system for access control.
     *
     * @return array<string> Required permission names
     */
    public static function getRequiredPermissions(): array
    {
        $security = static::validateSecurity();
        return $security['permissions'] ?? [];
    }

    /**
     * Get database migrations
     *
     * Returns an array of migration files that this extension provides.
     * Migrations should be in the extension's migrations directory.
     *
     * @return array<string> Array of migration file paths
     */
    public static function getMigrations(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $extensionDir = dirname($reflection->getFileName());
        $migrationsDir = $extensionDir . '/migrations';

        if (!is_dir($migrationsDir)) {
            return [];
        }

        $migrations = [];
        $files = glob($migrationsDir . '/*.php');

        foreach ($files as $file) {
            $migrations[] = $file;
        }

        sort($migrations); // Ensure consistent ordering
        return $migrations;
    }

    /**
     * Run extension migrations
     *
     * Executes all pending migrations for this extension.
     * Returns success status and any error messages.
     *
     * @return array Migration execution result
     */
    public static function runMigrations(): array
    {
        $migrations = static::getMigrations();

        if (empty($migrations)) {
            return [
                'success' => true,
                'message' => 'No migrations to run',
                'executed' => []
            ];
        }

        $executed = [];
        $errors = [];

        foreach ($migrations as $migration) {
            try {
                // Include and execute migration
                require_once $migration;
                $executed[] = basename($migration);
            } catch (\Throwable $e) {
                $errors[] = "Migration " . basename($migration) . " failed: " . $e->getMessage();
            }
        }

        return [
            'success' => empty($errors),
            'message' => empty($errors) ? 'All migrations executed successfully' : 'Some migrations failed',
            'executed' => $executed,
            'errors' => $errors
        ];
    }

    /**
     * Get extension assets
     *
     * Returns an array of static assets (CSS, JS, images) that this extension provides.
     * Assets should be in the extension's assets directory.
     *
     * @return array<string, array> Asset type => file paths mapping
     */
    public static function getAssets(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $extensionDir = dirname($reflection->getFileName());
        $assetsDir = $extensionDir . '/assets';

        if (!is_dir($assetsDir)) {
            return [];
        }

        $assets = [
            'css' => [],
            'js' => [],
            'images' => [],
            'fonts' => [],
            'other' => []
        ];

        // Scan for CSS files
        $cssFiles = glob($assetsDir . '/{css,styles}/*.css', GLOB_BRACE);
        foreach ($cssFiles as $file) {
            $assets['css'][] = str_replace($extensionDir . '/', '', $file);
        }

        // Scan for JS files
        $jsFiles = glob($assetsDir . '/{js,scripts}/*.js', GLOB_BRACE);
        foreach ($jsFiles as $file) {
            $assets['js'][] = str_replace($extensionDir . '/', '', $file);
        }

        // Scan for image files
        $imageFiles = glob($assetsDir . '/{images,img}/*.{png,jpg,jpeg,gif,svg,webp}', GLOB_BRACE);
        foreach ($imageFiles as $file) {
            $assets['images'][] = str_replace($extensionDir . '/', '', $file);
        }

        // Scan for font files
        $fontFiles = glob($assetsDir . '/fonts/*.{ttf,otf,woff,woff2,eot}', GLOB_BRACE);
        foreach ($fontFiles as $file) {
            $assets['fonts'][] = str_replace($extensionDir . '/', '', $file);
        }

        return $assets;
    }

    /**
     * Get API endpoints
     *
     * Returns an array of API endpoints that this extension exposes.
     * Used for REST API and route registration.
     *
     * @return array<string, array> Endpoint definitions
     */
    public static function getApiEndpoints(): array
    {
        return [];
    }

    /**
     * Get configuration schema
     *
     * Returns a JSON schema for validating this extension's configuration.
     * Used to validate settings and ensure proper configuration.
     *
     * @return array JSON schema for extension configuration
     */
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false
        ];
    }

    /**
     * Validate configuration
     *
     * Validates the provided configuration against the extension's schema.
     * Returns validation result with any errors.
     *
     * @param array $config Configuration to validate
     * @return array Validation result
     */
    public static function validateConfig(array $config): array
    {
        $schema = static::getConfigSchema();

        // Basic validation - in production, use a proper JSON schema validator
        $errors = [];

        // Check required properties
        $required = $schema['required'] ?? [];
        foreach ($required as $property) {
            if (!isset($config[$property])) {
                $errors[] = "Required property '$property' is missing";
            }
        }

        // Check property types if specified
        $properties = $schema['properties'] ?? [];
        foreach ($config as $key => $value) {
            if (isset($properties[$key]['type'])) {
                $expectedType = $properties[$key]['type'];
                $actualType = gettype($value);

                if ($expectedType === 'integer' && $actualType !== 'integer') {
                    $errors[] = "Property '$key' should be integer, got $actualType";
                } elseif ($expectedType === 'string' && $actualType !== 'string') {
                    $errors[] = "Property '$key' should be string, got $actualType";
                } elseif ($expectedType === 'boolean' && $actualType !== 'boolean') {
                    $errors[] = "Property '$key' should be boolean, got $actualType";
                } elseif ($expectedType === 'array' && $actualType !== 'array') {
                    $errors[] = "Property '$key' should be array, got $actualType";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
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
