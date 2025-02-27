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
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const INVALID = 2;
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
    public function execute(array $args = []): ?int
    {
        // Default implementation
        return self::SUCCESS;
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
     * Display warning message in yellow
     * 
     * @param string $message Warning message
     */
    protected function warning(string $message): void
    {
        echo "\033[33m" . $message . "\033[0m\n";
    }

    /**
     * Display tip message in cyan
     * 
     * @param string $message Tip message
     */
    protected function tip(string $message): void
    {
        echo "\033[36mTip: " . $message . "\033[0m\n";
    }

    /**
     * Check if application is running in production environment
     */
    protected function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

        /**
     * Display a generic output line
     * 
     * Outputs a plain line to the console.
     * 
     * @param string $message Line message
     */
    protected function line(string $message = ''): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Apply color to text for console output
     * 
     * @param string $text Text to color
     * @param string $color Color name (supported: black, red, green, yellow, blue, magenta, cyan, white)
     * @return string Colored text for console output
     */
    protected function colorText(string $text, string $color): string
    {
        $colors = [
            'black'   => '0;30',
            'red'     => '0;31',
            'green'   => '0;32',
            'yellow'  => '0;33',
            'blue'    => '0;34',
            'magenta' => '0;35',
            'cyan'    => '0;36',
            'white'   => '0;37',
        ];

        if (!isset($colors[$color])) {
            return $text; // Return normal text if color is not found
        }

        return "\033[" . $colors[$color] . "m" . $text . "\033[0m";
    }
}