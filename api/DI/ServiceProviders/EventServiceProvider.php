<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Events\Listeners\CacheInvalidationListener;
use Glueful\Events\Listeners\SecurityMonitoringListener;
use Glueful\Events\Listeners\PerformanceMonitoringListener;
use Glueful\Events\Listeners\AuditLoggingListener;
use Glueful\Extensions\ExtensionEventRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

/**
 * Event Service Provider
 *
 * Registers Symfony EventDispatcher and core event listeners
 * for the Glueful framework event system.
 *
 * @package Glueful\DI\ServiceProviders
 */
class EventServiceProvider implements ServiceProviderInterface
{
    /**
     * Register services in the container
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        // Register Symfony EventDispatcher as singleton
        $container->singleton(EventDispatcherInterface::class, function () {
            return new EventDispatcher();
        });

        // Register alias for contracts interface
        $container->alias(ContractsEventDispatcherInterface::class, EventDispatcherInterface::class);

        // Register core event listeners
        $this->registerCoreEventListeners($container);
    }

    /**
     * Bootstrap services after all providers are registered
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        // Only register event system if events are enabled in config
        if ($this->areEventsEnabled()) {
            // Register core event subscribers
            $this->registerCoreEventSubscribers($eventDispatcher, $container);

            // Register extension event subscribers
            $this->registerExtensionEventSubscribers($eventDispatcher, $container);
        }
    }

    /**
     * Register core framework event listeners
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerCoreEventListeners(ContainerInterface $container): void
    {
        // Cache invalidation listener
        $container->singleton(CacheInvalidationListener::class, function ($container) {
            return new CacheInvalidationListener(
                $container->get(\Glueful\Cache\CacheStore::class)
            );
        });

        // Security monitoring listener
        $container->singleton(SecurityMonitoringListener::class, function ($container) {
            return new SecurityMonitoringListener(
                $container->get(\Glueful\Logging\LogManager::class)
            );
        });

        // Performance monitoring listener
        $container->singleton(PerformanceMonitoringListener::class, function ($container) {
            return new PerformanceMonitoringListener(
                $container->get(\Glueful\Logging\LogManager::class)
            );
        });

        // Extension event registry
        $container->singleton(ExtensionEventRegistry::class, function ($container) {
            return new ExtensionEventRegistry(
                $container->get(EventDispatcherInterface::class),
                $container->get(\Glueful\Logging\LogManager::class)
            );
        });
    }

    /**
     * Register core event subscribers
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerCoreEventSubscribers(
        EventDispatcherInterface $eventDispatcher,
        ContainerInterface $container
    ): void {
        // Core event subscribers with configuration-based registration
        $subscribers = [
            'cache_invalidation' => [
                'class' => CacheInvalidationListener::class,
                'factory' => function () use ($container) {
                    return $container->get(CacheInvalidationListener::class);
                }
            ]
        ];

        // Register subscribers based on configuration
        foreach ($subscribers as $listenerKey => $config) {
            if ($this->isListenerEnabled($listenerKey) && class_exists($config['class'])) {
                $subscriber = $config['factory']();
                $eventDispatcher->addSubscriber($subscriber);
            }
        }

        // Register the legacy CacheInvalidationService if needed
        if (
            $this->isListenerEnabled('cache_invalidation') &&
            class_exists(\Glueful\Cache\CacheInvalidationService::class)
        ) {
            \Glueful\Cache\CacheInvalidationService::registerWithEventDispatcher($eventDispatcher);
        }
    }

    /**
     * Register extension event subscribers
     *
     * @param EventDispatcherInterface $eventDispatcher Event dispatcher
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerExtensionEventSubscribers(
        EventDispatcherInterface $eventDispatcher,
        ContainerInterface $container
    ): void {
        // Use the ExtensionEventRegistry for proper subscriber registration
        if ($container->has(ExtensionEventRegistry::class)) {
            $registry = $container->get(ExtensionEventRegistry::class);

            // Get loaded extensions from extension manager if available
            if ($container->has(\Glueful\Helpers\ExtensionsManager::class)) {
                $extensionManager = $container->get(\Glueful\Helpers\ExtensionsManager::class);
                $loadedExtensions = $extensionManager->getLoadedExtensions();

                // Register all extension event subscribers
                $registry->registerExtensionSubscribers($loadedExtensions);
            }
        }
    }

    /**
     * Check if events are enabled in configuration
     *
     * @return bool
     */
    private function areEventsEnabled(): bool
    {
        // Check if events are explicitly disabled in config
        if (function_exists('config')) {
            return config('events.enabled', true);
        }

        // Default to enabled if config function not available
        return true;
    }

    /**
     * Check if a specific listener is enabled in configuration
     *
     * @param string $listenerKey
     * @return bool
     */
    private function isListenerEnabled(string $listenerKey): bool
    {
        if (function_exists('config')) {
            return config("events.listeners.{$listenerKey}", true);
        }

        return true;
    }
}
