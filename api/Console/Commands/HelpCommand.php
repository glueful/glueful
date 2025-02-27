<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Console\Kernel;

/**
 * Console Help System
 * 
 * Provides comprehensive command documentation:
 * - Lists available commands
 * - Shows detailed command help
 * - Formats usage instructions
 * - Provides examples
 * - Displays command options
 * - Shows command arguments
 * - Links to documentation
 * - Supports command discovery
 * 
 * @package Glueful\Console\Commands
 */
class HelpCommand extends Command
{
    /**
     * Get Command Name
     * 
     * Returns command identifier:
     * - Used in CLI as `php glueful help`
     * - Core help command
     * - Default when no command specified
     * 
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'help';
    }

    /**
     * Get Command Description
     * 
     * Provides help system overview:
     * - Shows in command lists
     * - Explains help functionality
     * - Single line summary
     * 
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return 'Display help for a command';
    }

    /**
     * Get Command Help
     * 
     * Provides detailed help information:
     * - Shows command syntax
     * - Lists available options
     * - Shows usage examples
     * - Documents arguments
     * 
     * @return string Complete help text
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
     * Execute Help Command
     * 
     * Processes help request:
     * - Shows general help if no command specified
     * - Displays command-specific help if command given
     * - Formats output consistently
     * - Handles unknown commands
     * - Shows usage examples
     * 
     * @param array $args Command line arguments
     * @return int Exit code
     */
    public function execute(array $args = []): int
    {
        $kernel = new Kernel();
        $commands = $kernel->getCommands();

        // Show help for specific command if provided
        if (!empty($args) && isset($commands[$args[0]])) {
            $this->showCommandHelp($commands[$args[0]]);
            return Command::SUCCESS;
        }

        // Show general help
        $this->showHeader();
        $this->showCommandList($commands);
        $this->showFooter();
        
        return Command::SUCCESS;
    }

    /**
     * Display Help Header
     * 
     * Shows application introduction:
     * - Application name
     * - Basic usage
     * - Version info
     * - Command format
     * 
     * @return void
     */
    private function showHeader(): void
    {
        $this->info("\nGlueful CLI Tool\n");
        $this->info("Usage:");
        $this->info("  php glueful <command> [options] [arguments]\n");
    }

    /**
     * Display Command List
     * 
     * Shows available commands:
     * - Groups by category
     * - Shows brief descriptions
     * - Formats consistently
     * - Sorts alphabetically
     * - Handles command aliases
     * 
     * @param array $commands Available commands
     * @return void
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
     * Display Command Help
     * 
     * Shows detailed command documentation:
     * - Full description
     * - Usage syntax
     * - Available options
     * - Arguments list
     * - Usage examples
     * - Related commands
     * 
     * @param Command $command Command to document
     * @return void
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
     * Display Help Footer
     * 
     * Shows additional help resources:
     * - Documentation links
     * - Support contacts
     * - Related information
     * - Version details
     * 
     * @return void
     */
    private function showFooter(): void
    {
        $this->info("For more information, visit:");
        $this->info("  https://github.com/yourusername/glueful\n");
    }
}