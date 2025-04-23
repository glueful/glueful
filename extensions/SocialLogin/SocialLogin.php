<?php
declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin;

use Glueful\Extensions;
use Glueful\Auth\AuthBootstrap;
use Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider;
use Glueful\Helpers\Utils;
use Glueful\Helpers\ExtensionsManager;

/**
 * Social Login Extension
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
 * @package Glueful\Extensions\SocialLogin
 */
class SocialLogin extends Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];
    
    /** @var array Supported social providers */
    private static array $supportedProviders = ['google', 'facebook', 'github'];
    
    /**
     * Initialize extension
     * 
     * Sets up social authentication providers and registers them with
     * the authentication system.
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Load configuration
        self::loadConfig();
        
        // Register providers with the authentication system
        self::registerAuthProviders();
    }
    
    /**
     * Register extension-provided services
     * 
     * @return void
     */
    public static function registerServices(): void
    {
        // Could integrate with a service container if needed
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
            'enabled_providers' => ['google', 'facebook', 'github'],
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
     * Register social authentication providers
     * 
     * Initializes and registers authentication providers with the
     * authentication system.
     * 
     * @return void
     */
    private static function registerAuthProviders(): void
    {
        // Initialize the authentication system
        $authManager = AuthBootstrap::getManager();
        
        // Register providers based on configuration
        $enabledProviders = self::$config['enabled_providers'] ?? [];
        
        // Google provider
        if (in_array('google', $enabledProviders)) {
            try {
                $googleProvider = new GoogleAuthProvider();
                $authManager->registerProvider('google', $googleProvider);
            } catch (\Exception $e) {
                error_log("Failed to register Google auth provider: " . $e->getMessage());
            }
        }
        
        // Facebook provider
        if (in_array('facebook', $enabledProviders)) {
            try {
                $facebookProvider = new FacebookAuthProvider();
                $authManager->registerProvider('facebook', $facebookProvider);
            } catch (\Exception $e) {
                error_log("Failed to register Facebook auth provider: " . $e->getMessage());
            }
        }
        
        // GitHub provider
        if (in_array('github', $enabledProviders)) {
            try {
                $githubProvider = new GithubAuthProvider();
                $authManager->registerProvider('github', $githubProvider);
            } catch (\Exception $e) {
                error_log("Failed to register GitHub auth provider: " . $e->getMessage());
            }
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
            'name' => 'SocialLogin',
            'description' => 'Provides social authentication through Google, Facebook and GitHub',
            'version' => '1.0.0',
            'author' => 'Glueful Extensions Team',
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => []
            ]
        ];
    }
}