<?php

namespace Glueful\Console;

/**
 * Base Command Class
 * 
 * Abstract base class for console commands.
 * Provides common functionality for command-line interface commands.
 */
abstract class Command
{
    /**
     * Get command name
     * 
     * Returns unique identifier for the command.
     * 
     * @return string Command name
     */
    abstract public function getName(): string;
    
    /**
     * Get command description
     * 
     * Returns short description of command purpose.
     * 
     * @return string Command description
     */
    public function getDescription(): string
    {
        return 'No description available';
    }
    
    /**
     * Get command help text
     * 
     * Returns detailed usage instructions.
     * 
     * @return string Help text
     */
    public function getHelp(): string
    {
        return $this->getDescription();
    }

    /**
     * Execute command
     * 
     * Runs command with provided arguments.
     * 
     * @param array $args Command arguments
     */
    public function execute(array $args = []): void
    {
        // Default implementation
    }

    /**
     * Get command argument
     * 
     * Retrieves specific argument by name.
     * 
     * @param string $name Argument name
     * @return mixed Argument value or null if not found
     */
    protected function getArgument(string $name)
    {
        return $this->arguments[$name] ?? null;
    }

    /**
     * Display error message
     * 
     * Outputs formatted error to console.
     * 
     * @param string $message Error message
     */
    protected function error(string $message): void
    {
        echo "Error: " . $message . PHP_EOL;
    }

    /**
     * Display info message
     * 
     * Outputs formatted information to console.
     * 
     * @param string $message Info message
     */
    protected function info(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Output success message in green
     * 
     * @param string $message Message to display
     */
    protected function success(string $message): void
    {
        echo "\033[32m" . $message . "\033[0m\n";
    }

    /**
     * Check if application is running in production environment
     */
    protected function isProduction(): bool
    {
        return config('app.env') === 'production';
    }
}