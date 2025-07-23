<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

/**
 * VarDumper Service Provider
 *
 * Registers Symfony VarDumper services in the DI container.
 * Configures dumper for CLI and web environments.
 *
 * @package Glueful\DI\ServiceProviders
 */
class VarDumperServiceProvider implements ServiceProviderInterface
{
    /**
     * Register VarDumper services
     */
    public function register(ContainerBuilder $container): void
    {
        // Only register in development environment
        if (env('APP_ENV') !== 'development' || !env('APP_DEBUG', false)) {
            return;
        }

        // Register VarCloner
        $container->register(VarCloner::class)
            ->setFactory([$this, 'createVarCloner'])
            ->setPublic(true);

        // Register CLI Dumper
        $container->register(CliDumper::class)
            ->setFactory([$this, 'createCliDumper'])
            ->setPublic(true);

        // Register HTML Dumper
        $container->register(HtmlDumper::class)
            ->setFactory([$this, 'createHtmlDumper'])
            ->setPublic(true);

        // Register appropriate dumper based on SAPI
        $container->register('var_dumper.dumper')
            ->setFactory([$this, 'createDumper'])
            ->setArguments([new Reference('service_container')])
            ->setPublic(true);
    }

    /**
     * Boot the service provider
     */
    public function boot(Container $container): void
    {
        // Only boot in development environment
        if (env('APP_ENV') !== 'development' || !env('APP_DEBUG', false)) {
            return;
        }

        if (!class_exists(VarDumper::class)) {
            return;
        }

        // Configure VarDumper handler
        VarDumper::setHandler(function ($var) use ($container) {
            $cloner = $container->get(VarCloner::class);
            $dumper = $container->get('var_dumper.dumper');
            $dumper->dump($cloner->cloneVar($var));
        });
    }

    /**
     * Get compiler passes for VarDumper services
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
        return 'vardumper';
    }

    /**
     * Factory method for creating VarCloner
     */
    public static function createVarCloner(): VarCloner
    {
        $cloner = new VarCloner();
        $cloner->setMaxItems(2500);
        $cloner->setMaxString(-1);
        return $cloner;
    }

    /**
     * Factory method for creating CliDumper
     */
    public static function createCliDumper(): CliDumper
    {
        $dumper = new CliDumper();
        $config = config('vardumper.dumpers.cli', []);

        $dumper->setColors($config['colors'] ?? true);
        if (isset($config['max_string_width']) && $config['max_string_width'] > 0) {
            $dumper->setMaxStringWidth($config['max_string_width']);
        }
        return $dumper;
    }

    /**
     * Factory method for creating HtmlDumper
     */
    public static function createHtmlDumper(): HtmlDumper
    {
        $dumper = new HtmlDumper();
        $config = config('vardumper.dumpers.html', []);

        $dumper->setTheme($config['theme'] ?? 'dark');
        if (isset($config['file_link_format'])) {
            $dumper->setDumpHeader($config['file_link_format']);
        }
        return $dumper;
    }

    /**
     * Factory method for creating appropriate dumper
     */
    public static function createDumper($container)
    {
        if ('cli' === PHP_SAPI) {
            return $container->get(CliDumper::class);
        }
        return $container->get(HtmlDumper::class);
    }
}
