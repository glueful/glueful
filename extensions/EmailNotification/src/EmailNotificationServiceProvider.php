<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\DI\ServiceProviders\BaseExtensionServiceProvider;
use Glueful\Logging\LogManager;

/**
 * Email Notification Service Provider
 *
 * Registers all services for the EmailNotification extension
 */
class EmailNotificationServiceProvider extends BaseExtensionServiceProvider
{
    /**
     * Get the extension name
     */
    public function getName(): string
    {
        return 'EmailNotification';
    }

    /**
     * Get the extension version
     */
    public function getVersion(): string
    {
        return '0.21.0';
    }

    /**
     * Get the extension description
     */
    public function getDescription(): string
    {
        return 'Provides email notification capabilities using SMTP/PHPMailer';
    }

    /**
     * Register services with the container
     */
    protected function registerExtensionServices(): void
    {
        // Register the email notification provider
        $this->factory(EmailNotificationProvider::class, [self::class, 'createEmailNotificationProvider']);

        // Register the email channel
        $this->factory(EmailChannel::class, [self::class, 'createEmailChannel']);

        // Register the email formatter
        $this->singleton(EmailFormatter::class);
    }

    /**
     * Boot the extension
     */
    public function boot(\Glueful\DI\Container $container): void
    {
        // Register the email channel with the notification system if available
        if (class_exists(\Glueful\Notifications\Services\ChannelManager::class)) {
            $provider = $container->get(EmailNotificationProvider::class);
            // Initialize the provider to register channels
            if (method_exists($provider, 'initialize')) {
                $provider->initialize();
            }
        }
    }

    /**
     * Register extension routes
     */
    public function routes(): void
    {
        // Email notification extension doesn't have routes
    }

    /**
     * Get extension dependencies
     */
    public function getDependencies(): array
    {
        // No dependencies on other extensions
        return [];
    }

    /**
     * Create email notification provider
     *
     * @return EmailNotificationProvider
     */
    public static function createEmailNotificationProvider(): EmailNotificationProvider
    {
        $config = require __DIR__ . '/config.php';
        return new EmailNotificationProvider($config);
    }

    /**
     * Create email channel
     *
     * @return EmailChannel
     */
    public static function createEmailChannel(): EmailChannel
    {
        $config = require __DIR__ . '/config.php';
        $formatter = new EmailFormatter(); // Simple creation without container dependency
        return new EmailChannel($config, $formatter);
    }
}
