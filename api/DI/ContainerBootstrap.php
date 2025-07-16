<?php

declare(strict_types=1);

namespace Glueful\DI;

use Glueful\DI\Container;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Providers\CoreServiceProvider;
use Glueful\DI\Providers\RepositoryServiceProvider;
use Glueful\DI\Providers\ControllerServiceProvider;
use Glueful\DI\Providers\ArchiveServiceProvider;
use Glueful\DI\ServiceProviders\FileServiceProvider;
use Glueful\DI\ServiceProviders\VarDumperServiceProvider;
use Glueful\DI\ServiceProviders\EventServiceProvider;
use Glueful\DI\ServiceProviders\ConsoleServiceProvider;
use Glueful\DI\ServiceProviders\LockServiceProvider;
use Glueful\DI\ServiceProviders\ExtensionServiceProvider;
use Glueful\DI\ServiceProviders\ConfigServiceProvider;
use Glueful\DI\ServiceProviders\ValidatorServiceProvider;
use Glueful\DI\ServiceProviders\SerializerServiceProvider;
use Glueful\DI\ServiceProviders\HttpClientServiceProvider;

/**
 * Container Bootstrap
 *
 * Provides a centralized way to initialize and configure the DI container
 */
class ContainerBootstrap
{
    private static ?Container $container = null;

    /**
     * Initialize the container with core services
     */
    public static function initialize(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        $container = new Container();

        // Register core service providers
        $container->register(new CoreServiceProvider());
        $container->register(new RepositoryServiceProvider());
        $container->register(new ControllerServiceProvider());
        $container->register(new ArchiveServiceProvider());
        $container->register(new FileServiceProvider());
        $container->register(new VarDumperServiceProvider());
        $container->register(new EventServiceProvider());
        $container->register(new ConsoleServiceProvider($container));
        $container->register(new LockServiceProvider());
        $container->register(new ExtensionServiceProvider());
        $container->register(new ConfigServiceProvider());
        $container->register(new ValidatorServiceProvider());
        $container->register(new SerializerServiceProvider());
        $container->register(new HttpClientServiceProvider());

        // Boot all providers
        $container->boot();

        // Don't lock the container - extensions need to register services

        self::$container = $container;
        return $container;
    }

    /**
     * Get the initialized container
     */
    public static function getContainer(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container not initialized. Call initialize() first.');
        }

        return self::$container;
    }

    /**
     * Register additional service providers
     */
    public static function registerProvider(ServiceProviderInterface $provider): void
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container not initialized. Call initialize() first.');
        }

        self::$container->register($provider);
    }

    /**
     * Add custom bindings to the container
     */
    public static function addBindings(array $bindings): void
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container not initialized. Call initialize() first.');
        }

        foreach ($bindings as $abstract => $concrete) {
            if (is_array($concrete)) {
                $singleton = $concrete['singleton'] ?? false;
                $implementation = $concrete['class'] ?? $concrete['concrete'] ?? $abstract;

                if ($singleton) {
                    self::$container->singleton($abstract, $implementation);
                } else {
                    self::$container->bind($abstract, $implementation);
                }
            } else {
                self::$container->bind($abstract, $concrete);
            }
        }
    }

    /**
     * Reset the container (useful for testing)
     */
    public static function reset(): void
    {
        self::$container = null;
    }
}
