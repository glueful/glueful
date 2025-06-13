<?php

declare(strict_types=1);

namespace Glueful\Extensions;

// Keep using statements
use Glueful\Auth\AuthBootstrap;
use Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider;
use Glueful\Extensions\SocialLogin\SocialLoginServiceProvider;
use Glueful\Helpers\ExtensionsManager;

/**
 * Social Login Extension
 * @description Provides social authentication through Google, Facebook and GitHub
 * @license MIT
 * @version 1.0.0
 * @author Glueful <your.email@example.com>
 *
 * Provides social authentication capabilities for Glueful:
 * - Google OAuth authentication
 * - Facebook OAuth authentication
 * - GitHub OAuth authentication
 * - Linkage between social and local accounts
 *
 * Features:
 * - Multiple provider support
 * - User profile synchronization
 * - Automatic registration for new users
 * - Token generation and session management
 * - Configuration through admin interface
 *
 * @package Glueful\Extensions
 */
class SocialLogin extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];

    /** @var array Supported social providers */
    private static array $supportedProviders = ['google', 'facebook', 'github', 'apple'];

    /**
     * Initialize extension
     *
     * Sets up social authentication providers and registers them with
     * the authentication system via DI container.
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Load configuration
        self::loadConfig();

        // Provider registration is now handled by the service provider
        // during the boot phase, so no explicit registration needed here
    }


    /**
     * Register extension-specific routes
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        // Routes are defined in separate file, will be auto-loaded
    }

    /**
     * Load configuration for the extension
     *
     * @return void
     */
    private static function loadConfig(): void
    {
        // Default configuration
        $defaultConfig = [
            'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
            'auto_register' => true,
            'link_accounts' => true,
            'sync_profile' => true,
        ];

        // Try to load config from file or database
        $configPath = ExtensionsManager::getConfigPath() . '/extensions/social_login.php';
        if (file_exists($configPath)) {
            $loadedConfig = require $configPath;
            self::$config = array_merge($defaultConfig, $loadedConfig);
        } else {
            self::$config = $defaultConfig;
        }
    }


    /**
     * Get extension configuration
     *
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Get extension metadata
     *
     * @return array Extension metadata for admin interface
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'Social Login',
            'description' => 'Provides social authentication through Google, Facebook and GitHub',
            'version' => '0.18.0',
            'author' => 'Glueful Extensions Team',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => []
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
        // Currently no dependencies on other extensions
        return [];
    }

    /**
     * Check environment-specific configuration
     *
     * Determines if the extension should be enabled in the current environment.
     *
     * @param string $environment Current environment (dev, staging, production)
     * @return bool Whether the extension should be enabled in this environment
     */
    public static function isEnabledForEnvironment(string $environment): bool
    {
        // Enable in all environments by default
        // Could be customized based on environment-specific requirements
        return true;
    }

    /**
     * Validate extension health
     *
     * Checks if the extension is functioning correctly by verifying:
     * - Required configuration values are present
     * - OAuth providers can be initialized
     * - Necessary dependencies are available
     *
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'execution_time' => 0,
            'database_queries' => 0,
            'cache_usage' => 0
        ];

        // Start execution time tracking
        $startTime = microtime(true);

        // Check configuration
        if (empty(self::$config)) {
            self::loadConfig();
            if (empty(self::$config)) {
                $healthy = false;
                $issues[] = 'Failed to load extension configuration';
            }
        }

        // Check provider dependencies
        $enabledProviders = self::$config['enabled_providers'] ?? [];
        foreach ($enabledProviders as $provider) {
            switch ($provider) {
                case 'google':
                    if (!class_exists('Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider')) {
                        $healthy = false;
                        $issues[] = 'Google Auth Provider class not found';
                    }
                    break;

                case 'facebook':
                    if (!class_exists('Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider')) {
                        $healthy = false;
                        $issues[] = 'Facebook Auth Provider class not found';
                    }
                    break;

                case 'github':
                    if (!class_exists('Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider')) {
                        $healthy = false;
                        $issues[] = 'GitHub Auth Provider class not found';
                    }
                    break;
            }
        }

        // Check if authentication system is available
        try {
            $container = app();
            $authManager = AuthBootstrap::getManager();
            if (!$authManager) {
                $healthy = false;
                $issues[] = 'Authentication manager not available';
            }

            // Check if providers are registered in the container
            $enabledProviders = self::$config['enabled_providers'] ?? [];
            foreach ($enabledProviders as $provider) {
                $providerClass = match ($provider) {
                    'google' => GoogleAuthProvider::class,
                    'facebook' => FacebookAuthProvider::class,
                    'github' => GithubAuthProvider::class,
                    'apple' => AppleAuthProvider::class,
                    default => null
                };

                if ($providerClass && !$container->has($providerClass)) {
                    $healthy = false;
                    $issues[] = "{$provider} provider not registered in DI container";
                }
            }
        } catch (\Exception $e) {
            $healthy = false;
            $issues[] = 'Error accessing authentication system: ' . $e->getMessage();
        }

        // Calculate execution time
        $metrics['execution_time'] = microtime(true) - $startTime;

        return [
            'healthy' => $healthy,
            'issues' => $issues,
            'metrics' => $metrics
        ];
    }

    /**
     * Get extension resource usage
     *
     * Returns information about resources used by this extension.
     *
     * @return array Resource usage metrics
     */
    public static function getResourceUsage(): array
    {
        // Basic resource measurements
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'provider_count' => count(self::$config['enabled_providers'] ?? [])
        ];

        return $metrics;
    }

    /**
     * Get the service provider for this extension
     *
     * @return \Glueful\DI\Interfaces\ServiceProviderInterface
     */
    public static function getServiceProvider(): \Glueful\DI\Interfaces\ServiceProviderInterface
    {
        return new SocialLoginServiceProvider();
    }
}
