<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
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
     * Register configuration services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Symfony Config processor
        $container->register(Processor::class)
            ->setPublic(true);

        // Register Glueful configuration processor
        $container->register(ConfigurationProcessor::class)
            ->setArguments([
                new Reference(Processor::class),
                new Reference('logger')
            ])
            ->setPublic(true);

        // Register configuration tools
        $container->register(ConfigurationDumper::class)
            ->setArguments([new Reference(ConfigurationProcessor::class)])
            ->setPublic(true);

        $container->register(IDESupport::class)
            ->setArguments([new Reference(ConfigurationProcessor::class)])
            ->setPublic(true);
    }

    /**
     * Boot configuration services after container is built and register schemas
     */
    public function boot(Container $container): void
    {
        $processor = $container->get(ConfigurationProcessor::class);

        // Register built-in configuration schemas
        $this->registerBuiltInSchemas($processor);
    }

    /**
     * Get compiler passes for configuration services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Configuration services don't need custom compiler passes
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'config';
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
