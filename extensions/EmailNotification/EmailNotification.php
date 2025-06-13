<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Extensions\EmailNotification\EmailNotificationProvider;
use Glueful\Extensions\EmailNotification\EmailNotificationServiceProvider;
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
     * the notification system via DI container.
     *
     * @return void
     */
    public static function initialize(): void
    {
        try {
            // Get the DI container
            $container = app();

            // Initialize logger
            self::$logger = $container->get(LogManager::class);

            // Load configuration
            self::loadConfig();

            // Get the provider from the container (registered by service provider)
            self::$provider = $container->get(EmailNotificationProvider::class);

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
        $defaultConfig = require __DIR__ . '/src/config.php';

        // Try to load main mail config
        $mailConfig = config('services.mail') ?? [];

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
            'version' => '0.21.0',
            'type' => 'core',
            'requiredBy' => ['NotificationSystem'],
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
        // For email notifications, you might want specialized configurations per environment
        if ($environment === 'dev' || $environment === 'testing') {
            // Check if we have a testing configuration that allows emails in dev
            return self::$config['allow_emails_in_development'] ?? false;
        }

        // Always enable in staging and production
        return true;
    }

    /**
     * Validate extension health
     *
     * Checks if the extension is functioning correctly by verifying:
     * - Required configuration values are present
     * - SMTP connection can be established
     * - Templates directory exists and is readable
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
                $issues[] = 'Failed to load email configuration';
            }
        }

        // Check provider initialization
        if (!self::$provider) {
            $healthy = false;
            $issues[] = 'Email notification provider not initialized';
        } else {
            // Check if provider is properly configured
            if (!self::$provider->isEmailProviderConfigured()) {
                $healthy = false;
                $issues[] = 'Email provider not properly configured';
            }
        }

        // Check template directory
        $templateDir = self::$config['template_dir'] ?? null;
        if (!$templateDir || !is_dir($templateDir) || !is_readable($templateDir)) {
            $healthy = false;
            $issues[] = 'Email template directory not found or not readable';
        }

        // Check required config values
        $requiredConfigs = ['from_email', 'from_name', 'driver'];
        foreach ($requiredConfigs as $requiredConfig) {
            if (empty(self::$config[$requiredConfig])) {
                $healthy = false;
                $issues[] = "Required configuration '$requiredConfig' is missing";
            }
        }

        // Check notifications channel manager
        try {
            $container = app();
            $channelManager = $container->get(ChannelManager::class);
            if (!$channelManager->hasChannel('email')) {
                $healthy = false;
                $issues[] = 'Email channel not registered with notification system';
            }
        } catch (\Exception $e) {
            $healthy = false;
            $issues[] = 'Error checking notification channels: ' . $e->getMessage();
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
            'peak_memory_usage' => memory_get_peak_usage(true)
        ];

        // Add email-specific metrics if provider is available
        if (self::$provider) {
            $emailMetrics = self::$provider->getMetrics();
            $metrics = array_merge($metrics, $emailMetrics);
        }

        return $metrics;
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

    /**
     * Get the service provider for this extension
     *
     * @return \Glueful\DI\Interfaces\ServiceProviderInterface
     */
    public static function getServiceProvider(): \Glueful\DI\Interfaces\ServiceProviderInterface
    {
        return new EmailNotificationServiceProvider();
    }
}
