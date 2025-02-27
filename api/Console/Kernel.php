<?php

namespace Glueful\Console;

/**
 * Console Application Kernel
 * 
 * Main entry point for command-line operations:
 * - Manages command registration and execution
 * - Handles command-line arguments
 * - Provides command discovery
 * - Validates PHP environment
 * - Auto-registers core commands
 * - Supports command help system
 * 
 * @package Glueful\Console
 */
class Kernel
{
    /** @var array<string, Command> Map of registered command names to instances */
    protected array $commands = [];

    /**
     * Initialize Console Kernel
     * 
     * Sets up console environment:
     * - Validates PHP version requirements
     * - Registers core command set
     * - Initializes command registry
     * - Configures help system
     * 
     * @throws \RuntimeException If PHP version is below 8.2.0
     */
    public function __construct()
    {
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            die(sprintf(
                "Fatal Error: PHP 8.2.0 or higher is required. Current version: %s\n",
                PHP_VERSION
            ));
        }

        // Auto-register commands
        $this->registerCommands([
            \Glueful\Console\Commands\HelpCommand::class,
            \Glueful\Console\Commands\GenerateJsonCommand::class,
            \Glueful\Console\Commands\MigrateCommand::class,
            \Glueful\Console\Commands\DatabaseStatusCommand::class,
            \Glueful\Console\Commands\DatabaseResetCommand::class,
            \Glueful\Console\Commands\ExtensionsCommand::class,
        ]);
    }

    /**
     * Register Command Classes
     * 
     * Adds commands to registry:
     * - Instantiates command objects
     * - Maps command names
     * - Validates command interfaces
     * - Sets up command dependencies
     * 
     * @param array $commands List of command class names
     * @return void
     */
    private function registerCommands(array $commands): void
    {
        foreach ($commands as $commandClass) {
            $command = new $commandClass();
            $this->commands[$command->getName()] = $command;
        }
    }

    /**
     * Execute Console Command
     * 
     * Main execution flow:
     * - Parses command line arguments
     * - Resolves target command
     * - Validates command input
     * - Executes command logic
     * - Falls back to help on error
     * 
     * @return void
     */
    public function run(): void
    {
        global $argv;
        $commandName = $argv[1] ?? 'help'; // Default to help
        
        // Get command arguments (everything after the command name)
        $args = array_slice($argv, 2);

        if (isset($this->commands[$commandName])) {
            $this->commands[$commandName]->execute($args);
        } else {
            echo "Unknown command: $commandName\n";
            $this->commands['help']->execute([$commandName]); // Pass unknown command to help
        }
    }

    /**
     * Get Available Commands
     * 
     * Returns registered command set:
     * - Includes core commands
     * - Lists custom commands
     * - Provides command metadata
     * 
     * @return array<string, Command> Map of command names to instances
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}