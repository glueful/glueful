<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
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
    public function register(ContainerInterface $container): void
    {
        // Only register in development environment
        if (env('APP_ENV') !== 'development' || !env('APP_DEBUG', false)) {
            return;
        }

        // Register VarCloner
        $container->singleton(VarCloner::class, function () {
            $cloner = new VarCloner();
            $cloner->setMaxItems(2500);
            $cloner->setMaxString(-1);
            return $cloner;
        });

        // Register CLI Dumper with enhanced configuration
        $container->singleton(CliDumper::class, function () {
            $dumper = new CliDumper();
            $config = config('vardumper.dumpers.cli', []);

            $dumper->setColors($config['colors'] ?? true);
            if (isset($config['max_string_width']) && $config['max_string_width'] > 0) {
                $dumper->setMaxStringWidth($config['max_string_width']);
            }
            return $dumper;
        });

        // Register HTML Dumper with enhanced configuration
        $container->singleton(HtmlDumper::class, function () {
            $dumper = new HtmlDumper();
            $config = config('vardumper.dumpers.html', []);

            $dumper->setTheme($config['theme'] ?? 'dark');
            if (isset($config['file_link_format'])) {
                $dumper->setDumpHeader($config['file_link_format']);
            }
            return $dumper;
        });

        // Register appropriate dumper based on SAPI
        $container->singleton('var_dumper.dumper', function ($container) {
            if ('cli' === PHP_SAPI) {
                return $container->get(CliDumper::class);
            }
            return $container->get(HtmlDumper::class);
        });
    }

    /**
     * Boot the service provider
     */
    public function boot(ContainerInterface $container): void
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
}
