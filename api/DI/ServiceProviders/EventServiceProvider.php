<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Glueful\Events\Listeners\CacheInvalidationListener;
use Glueful\Events\Listeners\SecurityMonitoringListener;
use Glueful\Events\Listeners\PerformanceMonitoringListener;
use Glueful\Extensions\ExtensionEventRegistry;
use Glueful\Extensions\ExtensionManager;
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
     * @param ContainerBuilder $container
     * @return void
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Symfony EventDispatcher
        $container->register(EventDispatcherInterface::class, EventDispatcher::class)
            ->setPublic(true)
            ->addTag(ServiceTags::EVENT_SUBSCRIBER);

        // Register alias for contracts interface
        $container->setAlias(ContractsEventDispatcherInterface::class, EventDispatcherInterface::class);

        // Register core event listeners
        $this->registerCoreEventListeners($container);
    }

    /**
     * Bootstrap services after all providers are registered
     *
     * @param Container $container
     * @return void
     */
    public function boot(Container $container): void
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
     * @param ContainerBuilder $container
     * @return void
     */
    protected function registerCoreEventListeners(ContainerBuilder $container): void
    {
        // Cache invalidation listener
        $container->register(CacheInvalidationListener::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true)
            ->addTag(ServiceTags::EVENT_LISTENER);

        // Security monitoring listener
        $container->register(SecurityMonitoringListener::class)
            ->setArguments([new Reference('logger')])
            ->setPublic(true)
            ->addTag(ServiceTags::EVENT_LISTENER);

        // Performance monitoring listener
        $container->register(PerformanceMonitoringListener::class)
            ->setArguments([new Reference('logger')])
            ->setPublic(true)
            ->addTag(ServiceTags::EVENT_LISTENER);

        // Extension event registry
        $container->register(ExtensionEventRegistry::class)
            ->setArguments([
                new Reference(EventDispatcherInterface::class),
                new Reference('logger')
            ])
            ->setPublic(true);
    }

    /**
     * Register core event subscribers
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param Container $container
     * @return void
     */
    protected function registerCoreEventSubscribers(
        EventDispatcherInterface $eventDispatcher,
        Container $container
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
     * @param Container $container
     * @return void
     */
    protected function registerExtensionEventSubscribers(
        EventDispatcherInterface $eventDispatcher,
        Container $container
    ): void {
        // Use the ExtensionEventRegistry for proper subscriber registration
        if ($container->has(ExtensionEventRegistry::class)) {
            $registry = $container->get(ExtensionEventRegistry::class);

            // Get loaded extensions from extension manager if available
            if ($container->has(ExtensionManager::class)) {
                $extensionManager = $container->get(ExtensionManager::class);
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

    /**
     * Get compiler passes for event services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Event listeners will be processed by TaggedServicePass
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'events';
    }
}
