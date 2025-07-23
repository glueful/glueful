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
            new ServiceProviders\SpaServiceProvider(),
        ];

        foreach ($providers as $provider) {
            $provider->boot(self::$container);
        }

        // Boot extension service providers
        self::bootExtensionServiceProviders();
    }

    private static function bootExtensionServiceProviders(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $extensionsConfig = $projectRoot . '/extensions/extensions.json';

        if (!file_exists($extensionsConfig)) {
            return;
        }

        $extensionsData = json_decode(file_get_contents($extensionsConfig), true);
        if (!isset($extensionsData['extensions'])) {
            return;
        }

        foreach ($extensionsData['extensions'] as $extensionName => $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            $serviceProviders = $config['provides']['services'] ?? [];
            foreach ($serviceProviders as $serviceProviderPath) {
                $absolutePath = $projectRoot . '/' . $serviceProviderPath;

                if (!file_exists($absolutePath)) {
                    continue;
                }

                // Build the class name from the path
                $pathInfo = pathinfo($serviceProviderPath);
                $className = $pathInfo['filename'];

                // Build full class name based on path structure
                $pathParts = explode('/', $serviceProviderPath);
                $fullClassName = null;

                if (count($pathParts) >= 5 && $pathParts[2] === 'src') {
                    $subNamespace = $pathParts[3];
                    $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$subNamespace}\\{$className}";
                } else {
                    $fullClassName = "Glueful\\Extensions\\{$extensionName}\\{$className}";
                }

                if (!class_exists($fullClassName)) {
                    continue;
                }

                // Create instance and boot if it has a boot method
                $serviceProvider = new $fullClassName();
                if (method_exists($serviceProvider, 'boot')) {
                    $serviceProvider->boot(self::$container);
                }
            }
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
