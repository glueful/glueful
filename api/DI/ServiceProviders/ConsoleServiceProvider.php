<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Glueful\Console\Application;

/**
 * Console Service Provider
 *
 * Registers Symfony Console services in the DI container:
 * - Symfony Console Application
 * - Console command classes
 *
 * @package Glueful\DI\ServiceProviders
 */
class ConsoleServiceProvider implements ServiceProviderInterface
{
    /**
     * Register console services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Symfony Console Application
        $container->register(Application::class)
            ->setArguments([new Reference('service_container')])
            ->setPublic(true)
            ->addTag(ServiceTags::CONSOLE_COMMAND);

        // Register command classes
        $this->registerCommands($container);
    }

    /**
     * Register Console Commands
     *
     * Registers all command classes in the DI container
     */
    private function registerCommands(ContainerBuilder $container): void
    {
        // Command classes (organized by functional groups)
        $commands = [
            // Migration commands
            \Glueful\Console\Commands\Migrate\RunCommand::class,
            \Glueful\Console\Commands\Migrate\CreateCommand::class,
            \Glueful\Console\Commands\Migrate\StatusCommand::class,
            \Glueful\Console\Commands\Migrate\RollbackCommand::class,
            // Development commands
            \Glueful\Console\Commands\ServeCommand::class,
            // Route commands
            \Glueful\Console\Commands\RouteCommand::class,
            // Cache commands
            \Glueful\Console\Commands\Cache\ClearCommand::class,
            \Glueful\Console\Commands\Cache\StatusCommand::class,
            \Glueful\Console\Commands\Cache\GetCommand::class,
            \Glueful\Console\Commands\Cache\SetCommand::class,
            \Glueful\Console\Commands\Cache\DeleteCommand::class,
            \Glueful\Console\Commands\Cache\TtlCommand::class,
            \Glueful\Console\Commands\Cache\ExpireCommand::class,
            \Glueful\Console\Commands\Cache\PurgeCommand::class,
            // Database commands
            \Glueful\Console\Commands\Database\StatusCommand::class,
            \Glueful\Console\Commands\Database\ResetCommand::class,
            \Glueful\Console\Commands\Database\ProfileCommand::class,
            // Generate commands
            \Glueful\Console\Commands\Generate\ControllerCommand::class,
            \Glueful\Console\Commands\Generate\ApiDefinitionsCommand::class,
            \Glueful\Console\Commands\Generate\ApiDocsCommand::class,
            \Glueful\Console\Commands\Generate\KeyCommand::class,
            // Extensions commands
            \Glueful\Console\Commands\Extensions\InfoCommand::class,
            \Glueful\Console\Commands\Extensions\EnableCommand::class,
            \Glueful\Console\Commands\Extensions\DisableCommand::class,
            \Glueful\Console\Commands\Extensions\CreateCommand::class,
            \Glueful\Console\Commands\Extensions\ValidateCommand::class,
            \Glueful\Console\Commands\Extensions\InstallCommand::class,
            \Glueful\Console\Commands\Extensions\DeleteCommand::class,
            \Glueful\Console\Commands\Extensions\BenchmarkCommand::class,
            \Glueful\Console\Commands\Extensions\DebugCommand::class,
            // System commands
            \Glueful\Console\Commands\InstallCommand::class,
            \Glueful\Console\Commands\System\CheckCommand::class,
            \Glueful\Console\Commands\System\ProductionCommand::class,
            \Glueful\Console\Commands\System\MemoryMonitorCommand::class,
            // Security commands
            \Glueful\Console\Commands\Security\CheckCommand::class,
            \Glueful\Console\Commands\Security\VulnerabilityCheckCommand::class,
            \Glueful\Console\Commands\Security\LockdownCommand::class,
            \Glueful\Console\Commands\Security\ResetPasswordCommand::class,
            \Glueful\Console\Commands\Security\ReportCommand::class,
            \Glueful\Console\Commands\Security\RevokeTokensCommand::class,
            \Glueful\Console\Commands\Security\ScanCommand::class,
            // Notification commands
            \Glueful\Console\Commands\Notifications\ProcessRetriesCommand::class,
            // Queue commands
            \Glueful\Console\Commands\Queue\WorkCommand::class,
            \Glueful\Console\Commands\Queue\AutoScaleCommand::class,
            \Glueful\Console\Commands\Queue\SchedulerCommand::class,
            // Archive commands
            \Glueful\Console\Commands\Archive\ManageCommand::class,
            // Configuration commands
            \Glueful\Console\Commands\Config\ValidateConfigCommand::class,
            \Glueful\Console\Commands\Config\GenerateDocsCommand::class,
            \Glueful\Console\Commands\Config\GenerateIDESupportCommand::class,
            // Container management commands
            \Glueful\Console\Commands\Container\ContainerDebugCommand::class,
            \Glueful\Console\Commands\Container\ContainerCompileCommand::class,
            \Glueful\Console\Commands\Container\ContainerValidateCommand::class,
        ];

        // Register commands with tags for automatic discovery
        foreach ($commands as $commandClass) {
            $container->register($commandClass)
                ->setPublic(true)
                ->addTag(ServiceTags::CONSOLE_COMMAND);
        }
    }

    /**
     * Boot console services after container is built
     */
    public function boot(Container $container): void
    {
        // Any post-registration initialization can go here
    }

    /**
     * Get compiler passes for console services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Console commands will be processed by TaggedServicePass
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'console';
    }
}
