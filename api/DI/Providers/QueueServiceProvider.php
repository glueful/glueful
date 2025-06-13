<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\Queue\QueueManager;
use Glueful\Queue\Registry\DriverRegistry;
use Glueful\Queue\Plugins\PluginManager;
use Glueful\Queue\Config\ConfigManager;
use Glueful\Queue\Config\ConfigValidator;
use Glueful\Queue\Failed\FailedJobProvider;
use Glueful\Queue\Monitoring\WorkerMonitor;

/**
 * Queue Service Provider
 *
 * Registers queue system services with the DI container
 */
class QueueServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Queue configuration services
        $container->singleton(ConfigManager::class, function ($container) {
            return new ConfigManager();
        });

        $container->singleton(ConfigValidator::class, function ($container) {
            return new ConfigValidator();
        });

        // Queue registry and plugin management
        $container->singleton(DriverRegistry::class, function ($container) {
            return new DriverRegistry();
        });

        $container->singleton(PluginManager::class, function ($container) {
            return new PluginManager();
        });

        // Queue manager - main service
        $container->singleton(QueueManager::class, function ($container) {
            return new QueueManager();
        });

        // Queue monitoring services
        $container->singleton(WorkerMonitor::class, function ($container) {
            return new WorkerMonitor();
        });

        $container->singleton(FailedJobProvider::class, function ($container) {
            return new FailedJobProvider();
        });

        // Backward compatibility aliases
        $container->singleton('queue', function ($container) {
            return $container->get(QueueManager::class);
        });

        $container->singleton('queue.failed', function ($container) {
            return $container->get(FailedJobProvider::class);
        });

        $container->singleton('queue.monitor', function ($container) {
            return $container->get(WorkerMonitor::class);
        });
    }

    public function boot(ContainerInterface $container): void
    {
        // Boot queue system
        $pluginManager = $container->get(PluginManager::class);
        $pluginManager->discoverPlugins();

        $driverRegistry = $container->get(DriverRegistry::class);
        $driverRegistry->discoverDrivers();

        // Validate configuration on boot if not in production
        if (config('app.env') !== 'production') {
            try {
                $configManager = $container->get(ConfigManager::class);
                $validator = $container->get(ConfigValidator::class);

                $result = $validator->validate($configManager->all());

                if (!$result->isValid() && config('queue.strict_validation', false)) {
                    throw new \RuntimeException(
                        'Queue configuration validation failed: ' . $result->getErrorSummary()
                    );
                }
            } catch (\Exception $e) {
                // Log configuration validation errors but don't fail boot
                error_log('Queue configuration validation warning: ' . $e->getMessage());
            }
        }
    }
}