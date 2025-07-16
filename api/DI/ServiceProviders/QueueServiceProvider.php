<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Glueful\DI\Container;
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
    public function register(ContainerBuilder $container): void
    {
        // Queue configuration services
        $container->register(ConfigManager::class)
            ->setPublic(true);

        $container->register(ConfigValidator::class)
            ->setPublic(true);

        // Queue registry and plugin management
        $container->register(DriverRegistry::class)
            ->setPublic(true);

        $container->register(PluginManager::class)
            ->setPublic(true);

        // Queue manager - main service
        $container->register(QueueManager::class)
            ->setPublic(true);

        // Queue monitoring services
        $container->register(WorkerMonitor::class)
            ->setPublic(true);

        $container->register(FailedJobProvider::class)
            ->setPublic(true);

        // Backward compatibility aliases
        $container->setAlias('queue', QueueManager::class);
        $container->setAlias('queue.failed', FailedJobProvider::class);
        $container->setAlias('queue.monitor', WorkerMonitor::class);
    }

    public function boot(Container $container): void
    {
        // Boot queue system
        $pluginManager = $container->get(PluginManager::class);
        $pluginManager->loadPlugins();

        $driverRegistry = $container->get(DriverRegistry::class);
        $driverRegistry->loadDrivers();

        // Configuration validation is handled by ConfigManager during load
        // No additional validation needed here since it's already filtered and validated
    }

    /**
     * Get compiler passes for queue services
     */
    public function getCompilerPasses(): array
    {
        return [];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'queue';
    }
}
