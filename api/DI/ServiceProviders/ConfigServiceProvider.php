<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Symfony\Component\Config\Definition\Processor;
use Glueful\Configuration\ConfigurationProcessor;
use Glueful\Configuration\Schema\AppConfiguration;
use Glueful\Configuration\Schema\DatabaseConfiguration;
use Glueful\Configuration\Schema\CacheConfiguration;
use Glueful\Configuration\Schema\QueueConfiguration;
use Glueful\Configuration\Schema\SessionConfiguration;
use Glueful\Configuration\Schema\SecurityConfiguration;
use Glueful\Configuration\Schema\HttpConfiguration;
use Glueful\Configuration\Extension\ExtensionManifestSchema;
use Glueful\Configuration\Tools\ConfigurationDumper;
use Glueful\Configuration\Tools\IDESupport;
use Psr\Log\LoggerInterface;

/**
 * Configuration Service Provider
 *
 * Registers Symfony Config components and Glueful's configuration services
 * with the dependency injection container. Handles automatic registration
 * of built-in configuration schemas.
 *
 * @package Glueful\DI\ServiceProviders
 */
class ConfigServiceProvider implements ServiceProviderInterface
{
    /**
     * Register configuration services with the container
     */
    public function register(ContainerInterface $container): void
    {
        // Register Symfony Config processor
        $container->singleton(Processor::class, function () {
            return new Processor();
        });

        // Register Glueful configuration processor
        $container->singleton(ConfigurationProcessor::class, function (ContainerInterface $container) {
            return new ConfigurationProcessor(
                $container->get(Processor::class),
                $container->get(LoggerInterface::class)
            );
        });

        // Register configuration tools
        $container->singleton(ConfigurationDumper::class, function (ContainerInterface $container) {
            return new ConfigurationDumper(
                $container->get(ConfigurationProcessor::class)
            );
        });

        $container->singleton(IDESupport::class, function (ContainerInterface $container) {
            return new IDESupport(
                $container->get(ConfigurationProcessor::class)
            );
        });
    }

    /**
     * Boot configuration services and register schemas
     */
    public function boot(ContainerInterface $container): void
    {
        $processor = $container->get(ConfigurationProcessor::class);

        // Register built-in configuration schemas
        $this->registerBuiltInSchemas($processor);
    }

    /**
     * Register all built-in configuration schemas
     */
    private function registerBuiltInSchemas(ConfigurationProcessor $processor): void
    {
        // Register core schemas
        $processor->registerSchema(new AppConfiguration());
        $processor->registerSchema(new DatabaseConfiguration());
        $processor->registerSchema(new CacheConfiguration());
        $processor->registerSchema(new QueueConfiguration());
        $processor->registerSchema(new SessionConfiguration());
        $processor->registerSchema(new SecurityConfiguration());
        $processor->registerSchema(new HttpConfiguration());

        // Register extension schemas
        $processor->registerSchema(new ExtensionManifestSchema());
    }
}
