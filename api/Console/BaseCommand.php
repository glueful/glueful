<?php

namespace Glueful\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Glueful\DI\Container;

/**
 * Glueful Console Command Base Class
 *
 * Enhanced base class for console commands with Glueful integration:
 * - Provides DI container access
 * - Includes Glueful-specific styling and helpers
 * - Maintains compatibility with legacy command patterns
 * - Adds enhanced output formatting and interactivity
 *
 * @package Glueful\Console
 */
abstract class BaseCommand extends Command
{
    /** @var Container DI Container */
    protected Container $container;

    /** @var SymfonyStyle Enhanced output formatter */
    protected SymfonyStyle $io;

    /** @var InputInterface Command input */
    protected InputInterface $input;

    /** @var OutputInterface Command output */
    protected OutputInterface $output;

    /**
     * Initialize Command
     *
     * Sets up command with DI container:
     * - Resolves container from global container function
     * - Configures command properties
     * - Calls parent constructor
     *
     * @param Container|null $container DI Container instance
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? container();
        parent::__construct();
    }

    /**
     * Initialize Command Execution
     *
     * Sets up execution environment:
     * - Initializes SymfonyStyle for enhanced output
     * - Stores input/output references
     * - Configures interactive helpers
     *
     * @param InputInterface $input Command input
     * @param OutputInterface $output Command output
     * @return void
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * Get DI Container
     *
     * Provides access to the dependency injection container:
     * - Enables service resolution
     * - Allows access to application services
     * - Maintains container lifecycle
     *
     * @return Container
     */
    protected function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get Service from Container
     *
     * Convenience method for service resolution:
     * - Resolves service by class name or identifier
     * - Handles dependency injection
     * - Provides type-safe service access
     *
     * @param string $serviceId Service identifier
     * @return mixed Resolved service instance
     */
    protected function getService(string $serviceId)
    {
        return $this->container->get($serviceId);
    }

    // =====================================
    // Enhanced Output Methods
    // =====================================

    /**
     * Display Success Message
     *
     * Shows a formatted success message with green styling:
     * - Uses SymfonyStyle for consistent formatting
     * - Includes success icon
     * - Maintains legacy compatibility
     *
     * @param string $message Success message
     * @return void
     */
    protected function success(string $message): void
    {
        $this->io->success($message);
    }

    /**
     * Display Error Message
     *
     * Shows a formatted error message with red styling:
     * - Uses SymfonyStyle for consistent formatting
     * - Includes error icon
     * - Maintains legacy compatibility
     *
     * @param string $message Error message
     * @return void
     */
    protected function error(string $message): void
    {
        $this->io->error($message);
    }

    /**
     * Display Warning Message
     *
     * Shows a formatted warning message with yellow styling:
     * - Uses SymfonyStyle for consistent formatting
     * - Includes warning icon
     * - Maintains legacy compatibility
     *
     * @param string $message Warning message
     * @return void
     */
    protected function warning(string $message): void
    {
        $this->io->warning($message);
    }

    /**
     * Display Info Message
     *
     * Shows a formatted info message:
     * - Uses SymfonyStyle for consistent formatting
     * - Maintains legacy compatibility
     * - Provides clean information display
     *
     * @param string $message Info message
     * @return void
     */
    protected function info(string $message): void
    {
        $this->io->info($message);
    }

    /**
     * Display Note/Tip Message
     *
     * Shows a formatted note message:
     * - Uses SymfonyStyle for consistent formatting
     * - Provides helpful tips and notes
     * - Maintains legacy "tip" method compatibility
     *
     * @param string $message Note/tip message
     * @return void
     */
    protected function note(string $message): void
    {
        $this->io->note($message);
    }

    /**
     * Display Tip Message (Legacy Compatibility)
     *
     * Alias for note() method to maintain legacy compatibility:
     * - Provides same functionality as legacy tip()
     * - Uses enhanced SymfonyStyle formatting
     *
     * @param string $message Tip message
     * @return void
     */
    protected function tip(string $message): void
    {
        $this->note("Tip: " . $message);
    }

    /**
     * Display Plain Line
     *
     * Shows a plain text line:
     * - Maintains legacy compatibility
     * - Uses SymfonyStyle writeln for consistency
     *
     * @param string $message Line message
     * @return void
     */
    protected function line(string $message = ''): void
    {
        $this->io->writeln($message);
    }

    // =====================================
    // Enhanced Interactive Methods
    // =====================================

    /**
     * Ask Confirmation Question
     *
     * Prompts user for yes/no confirmation:
     * - Uses SymfonyStyle for consistent formatting
     * - Handles user input validation
     * - Provides default value support
     *
     * @param string $question Question text
     * @param bool $default Default answer (true = yes, false = no)
     * @return bool User's answer
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->io->confirm($question, $default);
    }

    /**
     * Ask Text Question
     *
     * Prompts user for text input:
     * - Uses SymfonyStyle for consistent formatting
     * - Supports default values
     * - Handles validation
     *
     * @param string $question Question text
     * @param string|null $default Default value
     * @return string User's answer
     */
    protected function ask(string $question, ?string $default = null): string
    {
        return $this->io->ask($question, $default);
    }

    /**
     * Ask Secret Question
     *
     * Prompts user for hidden input (passwords, etc.):
     * - Hides user input
     * - Uses SymfonyStyle for consistent formatting
     * - Provides secure input handling
     *
     * @param string $question Question text
     * @return string User's answer
     */
    protected function secret(string $question): string
    {
        return $this->io->askHidden($question);
    }

    /**
     * Display Choice Menu
     *
     * Shows a selection menu with options:
     * - Uses SymfonyStyle for consistent formatting
     * - Handles user selection
     * - Supports default values
     *
     * @param string $question Question text
     * @param array $choices Available choices
     * @param string|null $default Default choice
     * @return string Selected choice
     */
    protected function choice(string $question, array $choices, ?string $default = null): string
    {
        return $this->io->choice($question, $choices, $default);
    }

    // =====================================
    // Enhanced Display Methods
    // =====================================

    /**
     * Display Table
     *
     * Shows formatted table with data:
     * - Uses SymfonyStyle table formatting
     * - Supports headers and multiple rows
     * - Provides clean tabular display
     *
     * @param array $headers Table headers
     * @param array $rows Table rows
     * @return void
     */
    protected function table(array $headers, array $rows): void
    {
        $this->io->table($headers, $rows);
    }

    /**
     * Create Progress Bar
     *
     * Creates a progress bar for long operations:
     * - Uses SymfonyStyle progress bar
     * - Supports custom step counts
     * - Provides visual feedback
     *
     * @param int $max Maximum steps
     * @return ProgressBar Progress bar instance
     */
    protected function createProgressBar(int $max = 0): ProgressBar
    {
        return $this->io->createProgressBar($max);
    }

    /**
     * Display Progress Bar
     *
     * Shows a progress bar with completion callback:
     * - Handles progress bar lifecycle
     * - Executes callback with progress updates
     * - Provides clean progress display
     *
     * @param int $steps Total number of steps
     * @param callable $callback Callback function receiving progress bar
     * @return void
     */
    protected function progressBar(int $steps, callable $callback): void
    {
        $progressBar = $this->createProgressBar($steps);
        $progressBar->start();

        $callback($progressBar);

        $progressBar->finish();
        $this->io->newLine();
    }

    // =====================================
    // Utility Methods
    // =====================================

    /**
     * Check Production Environment
     *
     * Determines if application is running in production:
     * - Reads from configuration
     * - Used for safety checks
     * - Maintains legacy compatibility
     *
     * @return bool True if production environment
     */
    protected function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * Require Production Confirmation
     *
     * Forces confirmation for production operations:
     * - Checks environment
     * - Requires explicit confirmation
     * - Prevents accidental production changes
     *
     * @param string $operation Operation description
     * @return bool True if confirmed or not production
     */
    protected function confirmProduction(string $operation): bool
    {
        if (!$this->isProduction()) {
            return true;
        }

        $this->warning("You are about to {$operation} in PRODUCTION environment!");
        return $this->confirm("Are you sure you want to continue?", false);
    }
}
