<?php
declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\EmailNotification\EmailNotificationProvider;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Logging\LogManager;

/**
 * Email Notification Extension
 * @description Provides email notification channels for the system
 * @license MIT
 * @version 1.0.0
 * @author Glueful Extensions Team
 * 
 * Provides email notification capabilities for Glueful:
 * - SMTP email sending
 * - Email templates
 * - HTML and plain text formats
 * - Customizable email layouts
 * 
 * Features:
 * - Integration with notification system
 * - Template-based emails
 * - Support for various email providers
 * - Configurable settings
 * - Email delivery logging
 *
 * @package Glueful\Extensions
 */
class EmailNotification extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];
    
    /** @var EmailNotificationProvider The provider instance */
    private static ?EmailNotificationProvider $provider = null;
    
    /** @var LogManager Logger instance */
    private static ?LogManager $logger = null;
    
    /**
     * Initialize extension
     * 
     * Sets up the email notification provider and registers it with
     * the notification system.
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Initialize logger
        self::$logger = new LogManager('email_notification');
        
        try {
            // Load configuration
            self::loadConfig();
            
            // Create and initialize the provider
            self::$provider = new EmailNotificationProvider(self::$config);
            if (!self::$provider->initialize(self::$config)) {
                self::$logger->error('Failed to initialize email notification provider');
            }
            
            self::$logger->info('EmailNotification extension initialized successfully');
        } catch (\Exception $e) {
            if (self::$logger) {
                self::$logger->error('Error initializing EmailNotification extension: ' . $e->getMessage(), [
                    'exception' => $e
                ]);
            } else {
                error_log('Error initializing EmailNotification extension: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Register extension-provided services
     * 
     * Registers the email notification channel with the channel manager.
     * 
     * @return void
     */
    public static function registerServices(): void
    {
        try {
            if (self::$provider) {
                // Get the channel manager directly without using Container
                $channelManager = new \Glueful\Notifications\Services\ChannelManager();
                
                // Register the provider with the channel manager
                self::$provider->register($channelManager);
                
                if (self::$logger) {
                    self::$logger->info('EmailNotification provider registered successfully');
                }
            }
        } catch (\Exception $e) {
            if (self::$logger) {
                self::$logger->error('Error registering EmailNotification services: ' . $e->getMessage());
            } else {
                error_log('Error registering EmailNotification services: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Register middleware if needed
     * 
     * @return void
     */
    public static function registerMiddleware(): void
    {
        // No middleware needed for email notifications
    }
    
    /**
     * Load configuration for the extension
     * 
     * @return void
     */
    private static function loadConfig(): void
    {
        // Default configuration
        $defaultConfig = require __DIR__ . '/config.php';
        
        // Try to load main mail config
        $mailConfig = config('mail') ?? [];
        
        // Merge configurations with mail config taking precedence
        self::$config = array_merge($defaultConfig, $mailConfig);
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
            'name' => 'Email Notification',
            'description' => 'Provides email notification capabilities using SMTP/PHPMailer',
            'version' => '1.0.0',
            'author' => 'Glueful Extensions Team',
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => []
            ]
        ];
    }
    
    /**
     * Check if the email provider is properly configured
     * 
     * @return bool True if email provider is properly configured
     */
    public static function isConfigured(): bool
    {
        return self::$provider ? self::$provider->isEmailProviderConfigured() : false;
    }
    
    /**
     * Get the provider instance
     * 
     * @return EmailNotificationProvider|null The provider instance
     */
    public static function getProvider(): ?EmailNotificationProvider
    {
        return self::$provider;
    }
}