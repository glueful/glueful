<?php

declare(strict_types=1);

namespace Glueful\DI;

/**
 * Pure Symfony DI Bootstrap - Complete replacement
 */
class ContainerBootstrap
{
    private static ?Container $container = null;

    public static function initialize(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        $isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production' &&
                       !($_ENV['APP_DEBUG'] ?? false);

        self::$container = ContainerFactory::create($isProduction);
        self::bootContainer();

        return self::$container;
    }

    private static function bootContainer(): void
    {
        // Boot service providers that need post-compilation setup
        $providers = [
            new ServiceProviders\CoreServiceProvider(),
            new ServiceProviders\ConfigServiceProvider(),
            new ServiceProviders\SecurityServiceProvider(),
            new ServiceProviders\ValidatorServiceProvider(),
            new ServiceProviders\SerializerServiceProvider(),
            new ServiceProviders\HttpClientServiceProvider(),
            new ServiceProviders\RequestServiceProvider(),
            new ServiceProviders\FileServiceProvider(),
            new ServiceProviders\LockServiceProvider(),
            new ServiceProviders\EventServiceProvider(),
            new ServiceProviders\ConsoleServiceProvider(),
            new ServiceProviders\VarDumperServiceProvider(),
            new ServiceProviders\ExtensionServiceProvider(),
            new ServiceProviders\ArchiveServiceProvider(),
            new ServiceProviders\ControllerServiceProvider(),
            new ServiceProviders\QueueServiceProvider(),
            new ServiceProviders\RepositoryServiceProvider(),
        ];

        foreach ($providers as $provider) {
            $provider->boot(self::$container);
        }
    }

    public static function reset(): void
    {
        self::$container = null;
    }

    public static function getContainer(): ?Container
    {
        return self::$container;
    }
}
