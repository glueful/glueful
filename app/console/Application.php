<?php

namespace App\Console;

class Application
{
    protected array $commands = [];

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
            \App\Console\Commands\HelpCommand::class,
            \App\Console\Commands\GenerateJsonCommand::class,
            \App\Console\Commands\MigrateCommand::class,
            \App\Console\Commands\DatabaseStatusCommand::class,
            \App\Console\Commands\DatabaseResetCommand::class,
        ]);
    }

    private function registerCommands(array $commands)
    {
        foreach ($commands as $commandClass) {
            $command = new $commandClass();
            $this->commands[$command->getName()] = $command;
        }
    }

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

    public function getCommands(): array
    {
        return $this->commands;
    }
}