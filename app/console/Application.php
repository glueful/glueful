<?php

namespace Glueful\App\Console;

/**
 * Console Application
 * 
 * Handles command-line interface functionality.
 * Manages command registration and execution.
 */
class Application
{
    /** @var array<string, Command> Registered console commands */
    protected array $commands = [];

    /**
     * Constructor
     * 
     * Initializes console application and registers commands.
     * Validates PHP version requirement.
     * 
     * @throws \RuntimeException If PHP version is insufficient
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
            \Glueful\App\Console\Commands\HelpCommand::class,
            \Glueful\App\Console\Commands\GenerateJsonCommand::class,
            \Glueful\App\Console\Commands\MigrateCommand::class,
            \Glueful\App\Console\Commands\DatabaseStatusCommand::class,
            \Glueful\App\Console\Commands\DatabaseResetCommand::class,
        ]);
    }

    /**
     * Register command classes
     * 
     * Instantiates and stores command objects.
     * 
     * @param array $commands Array of command class names
     */
    private function registerCommands(array $commands)
    {
        foreach ($commands as $commandClass) {
            $command = new $commandClass();
            $this->commands[$command->getName()] = $command;
        }
    }

    /**
     * Run console application
     * 
     * Processes command line arguments and executes appropriate command.
     * Falls back to help command if none specified.
     */
    public function run()
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
     * Get registered commands
     * 
     * @return array<string, Command> Array of available commands
     */
    public function getCommands(): array
    {
        return $this->commands;
    }
}