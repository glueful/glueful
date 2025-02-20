<?php

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Application;

/**
 * Help Command
 * 
 * Provides documentation and usage information for console commands.
 * Lists available commands and displays detailed help for specific commands.
 */
class HelpCommand extends Command
{
    /**
     * Get command name
     * 
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'help';
    }

    /**
     * Get command description
     * 
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return 'Display help for a command';
    }

    /**
     * Get detailed help
     * 
     * @return string Command usage instructions
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  help [command]

Description:
  Displays help for a command. When no command is specified,
  displays the list of all available commands.

Arguments:
  command         The command to show help for

Examples:
  php glueful help
  php glueful help db:migrate
  php glueful help generate:json
HELP;
    }

    /**
     * Execute help command
     * 
     * Shows general help or command-specific help.
     * 
     * @param array $args Command arguments
     */
    public function execute(array $args = []): void
    {
        $app = new Application();
        $commands = $app->getCommands();

        // Show help for specific command if provided
        if (!empty($args) && isset($commands[$args[0]])) {
            $this->showCommandHelp($commands[$args[0]]);
            return;
        }

        // Show general help
        $this->showHeader();
        $this->showCommandList($commands);
        $this->showFooter();
    }

    /**
     * Display help header
     * 
     * Shows application name and basic usage.
     */
    private function showHeader(): void
    {
        $this->info("\nGlueful CLI Tool\n");
        $this->info("Usage:");
        $this->info("  php glueful <command> [options] [arguments]\n");
    }

    /**
     * Display command list
     * 
     * Shows all available commands with descriptions.
     * 
     * @param array $commands Available commands
     */
    private function showCommandList(array $commands): void
    {
        $this->info("Available Commands:");
        
        // Get max command name length for padding
        $maxLength = max(array_map(fn($cmd) => strlen($cmd->getName()), $commands));
        
        foreach ($commands as $command) {
            $name = str_pad($command->getName(), $maxLength + 2);
            $desc = method_exists($command, 'getDescription') 
                ? $command->getDescription() 
                : 'No description available';
                
            $this->info(sprintf("  %s  %s", $name, $desc));
        }
        
        $this->info("\nUse 'php glueful help <command>' for more information about a command.\n");
    }

    /**
     * Display command help
     * 
     * Shows detailed help for specific command.
     * 
     * @param Command $command Command to display help for
     */
    private function showCommandHelp(Command $command): void
    {
        $this->info("\nCommand: " . $command->getName() . "\n");
        
        if (method_exists($command, 'getDescription')) {
            $this->info("Description:");
            $this->info("  " . $command->getDescription() . "\n");
        }
        
        if (method_exists($command, 'getHelp')) {
            $this->info($command->getHelp());
        }
        
        $this->info('');
    }

    /**
     * Display help footer
     * 
     * Shows additional information and resources.
     */
    private function showFooter(): void
    {
        $this->info("For more information, visit:");
        $this->info("  https://github.com/yourusername/glueful\n");
    }
}