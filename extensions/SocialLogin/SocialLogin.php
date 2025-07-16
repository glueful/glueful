<?php

declare(strict_types=1);

namespace Glueful\Extensions;

// Keep using statements
use Glueful\Extensions\BaseExtension;

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
class SocialLogin extends BaseExtension
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
            'google' => [],
            'facebook' => [],
            'github' => [],
            'apple' => [],
        ];

        // Load config from the extension's src/config.php file
        $configPath = __DIR__ . '/src/config.php';
        if (file_exists($configPath)) {
            $loadedConfig = require $configPath;
            self::$config = array_merge($defaultConfig, $loadedConfig);
        } else {
            self::$config = $defaultConfig;
        }

        // Also check for custom config override in the main config directory
        $customConfigPath = 'config/extensions/social_login.php';
        if (file_exists($customConfigPath)) {
            $customConfig = require $customConfigPath;
            self::$config = array_merge(self::$config, $customConfig);
        }
    }

    /**
     * Get configuration for the extension
     *
     * @return array
     */
    public static function getConfig(): array
    {
        // Ensure config is loaded
        if (empty(self::$config)) {
            self::loadConfig();
        }
        return self::$config;
    }

    /**
     * Check extension health
     *
     * @return array Health status with 'healthy' (bool) and 'issues' (array) keys
     */
    public static function checkHealth(): array
    {
        return [
            'healthy' => true,
            'issues' => [],
            'metrics' => [
                'memory_usage' => memory_get_usage(true),
                'execution_time' => 0,
                'database_queries' => 0,
                'cache_usage' => 0
            ]
        ];
    }
}
