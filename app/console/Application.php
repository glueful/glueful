<?php

namespace App\Console;

class Application
{
    protected array $commands = [];

    public function __construct()
    {
        // Auto-register commands
        $this->registerCommands([
            \App\Console\Commands\HelloCommand::class,
            \App\Console\Commands\HelpCommand::class,
            \App\Console\Commands\GenerateJsonCommand::class,
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

        if (isset($this->commands[$commandName])) {
            $this->commands[$commandName]->execute();
        } else {
            echo "Unknown command: $commandName\n";
            $this->commands['help']->execute(); // Show help if command not found
        }
    }

    public function getCommands(): array
    {
        return $this->commands;
    }
}