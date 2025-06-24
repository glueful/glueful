<?php

namespace Glueful\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Glueful\DI\Interfaces\ContainerInterface;

/**
 * Glueful Symfony Console Application
 *
 * Enhanced console application built on Symfony Console:
 * - Integrates with Glueful's DI container
 * - Auto-registers commands with dependency injection
 * - Provides consistent branding and help system
 * - Organized command structure by functional groups
 * - Supports modern Symfony Console patterns
 *
 * @package Glueful\Console
 */
class Application extends BaseApplication
{
    /** @var ContainerInterface DI Container */
    protected ContainerInterface $container;

    /** @var array<string> List of command classes */
    protected array $commands = [
        // Migration commands
        \Glueful\Console\Commands\Migrate\RunCommand::class,
        \Glueful\Console\Commands\Migrate\CreateCommand::class,
        \Glueful\Console\Commands\Migrate\StatusCommand::class,
        \Glueful\Console\Commands\Migrate\RollbackCommand::class,
        // Development commands
        \Glueful\Console\Commands\ServeCommand::class,
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
        \Glueful\Console\Commands\Security\AuditCommand::class,
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
    ];

    /**
     * Initialize Glueful Console Application
     *
     * Sets up Symfony Console with Glueful integration:
     * - Configures application name and version
     * - Integrates DI container
     * - Registers available commands
     * - Sets up enhanced help system
     *
     * @param ContainerInterface $container DI Container instance
     * @param string $version Application version
     */
    public function __construct(ContainerInterface $container, string $version = '1.0.0')
    {
        parent::__construct('Glueful CLI', $version);

        $this->container = $container;
        $this->registerCommands();
        $this->configureApplication();
    }

    /**
     * Register All Commands
     *
     * Registers all console commands:
     * - Resolves commands via DI container
     * - Handles command dependencies
     * - Validates command structure
     * - Sets up command metadata
     *
     * @return void
     */
    private function registerCommands(): void
    {
        // Register commands
        foreach ($this->commands as $commandClass) {
            $command = $this->container->get($commandClass);
            if ($command instanceof Command) {
                $this->add($command);
            }
        }

        // All commands are now using Symfony Console
    }

    /**
     * Configure Application Settings
     *
     * Customizes Symfony Console for Glueful:
     * - Sets up custom help formatter
     * - Configures error handling
     * - Adds Glueful-specific features
     * - Sets console styling
     *
     * @return void
     */
    private function configureApplication(): void
    {
        // Set catch exceptions to true for better error handling
        $this->setCatchExceptions(true);

        // Configure auto-exit behavior
        $this->setAutoExit(true);

        // Set default command to list available commands
        $this->setDefaultCommand('list');
    }

    /**
     * Get DI Container
     *
     * Provides access to the DI container for commands:
     * - Allows service resolution
     * - Enables dependency injection
     * - Maintains container lifecycle
     *
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Add Command Class
     *
     * Registers a new command:
     * - Validates command class
     * - Resolves via DI container
     * - Adds to command registry
     * - Updates command list
     *
     * @param string $commandClass Command class name
     * @return void
     */
    public function addCommand(string $commandClass): void
    {
        if (!in_array($commandClass, $this->commands)) {
            $this->commands[] = $commandClass;

            // Register immediately if application is already initialized
            $command = $this->container->get($commandClass);
            if ($command instanceof Command) {
                $this->add($command);
            }
        }
    }

    /**
     * Get Registered Commands
     *
     * Returns list of registered commands:
     * - Provides command class names
     * - Used for debugging and introspection
     *
     * @return array<string>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}
