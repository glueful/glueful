<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\DI\Providers\ExtensionServiceProvider;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Logging\LogManager;

/**
 * Email Notification Service Provider
 *
 * Registers all services for the EmailNotification extension
 */
class EmailNotificationServiceProvider extends ExtensionServiceProvider
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
    public function register(ContainerInterface $container): void
    {
        // Register the email notification provider
        $container->singleton(EmailNotificationProvider::class, function ($container) {
            $config = require __DIR__ . '/config.php';
            return new EmailNotificationProvider($config);
        });

        // Register the email channel
        $container->bind(EmailChannel::class, function ($container) {
            $config = require __DIR__ . '/config.php';
            $formatter = $container->has(EmailFormatter::class)
                ? $container->get(EmailFormatter::class)
                : new EmailFormatter();
            return new EmailChannel($config, $formatter);
        });

        // Register the email formatter
        $container->bind(EmailFormatter::class, function ($container) {
            return new EmailFormatter();
        });
    }

    /**
     * Boot the extension
     */
    public function boot(ContainerInterface $container): void
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
}
