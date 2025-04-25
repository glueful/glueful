<?php
declare(strict_types=1);

namespace Glueful\Console\Commands\Notifications;

use Glueful\Console\Command;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Repository\NotificationRepository;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\ChannelManager;

/**
 * Process Notification Retries Command
 * 
 * Processes queued notification retries that were scheduled due to previous delivery failures.
 * This command should be scheduled to run periodically (e.g., every 5-15 minutes) to attempt
 * redelivery of failed notifications according to their scheduled retry time.
 * 
 * @package Glueful\Console\Commands\Notifications
 */
class ProcessNotificationRetriesCommand extends Command
{
    /**
     * @var NotificationService Notification service instance
     */
    private NotificationService $notificationService;
    
    /**
     * @var NotificationRetryService Notification retry service instance
     */
    private NotificationRetryService $retryService;
    
    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize logger properly
        $this->logger = new LogManager();
        
        // Set up notification service
        $this->notificationService = new NotificationService(
            new NotificationDispatcher(
                new ChannelManager()
            ),
            new NotificationRepository()
        );
        
        // Initialize retry service with logger and configuration
        $this->retryService = new NotificationRetryService(
            $this->logger,
            null, // Use default NotificationRepository
            config('extensions.EmailNotification.retry') ?? []
        );
    }
    
    /**
     * Get the command name
     * 
     * @return string Command name
     */
    public function getName(): string
    {
        return 'notifications:process-retries';
    }
    
    /**
     * Get Command Description
     * 
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return 'Process queued notification retries';
    }
    
    /**
     * Get Command Help
     * 
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  notifications:process-retries [options]

Description:
  Processes queued notification retries that were scheduled due to
  previous delivery failures. This command checks the retry queue and
  attempts to redeliver notifications that are due for retry.

Options:
  --limit=<n>    Maximum number of retries to process (default: 50)
  -h, --help     Show this help message

Examples:
  php glueful notifications:process-retries
  php glueful notifications:process-retries --limit=100
HELP;
    }
    
    /**
     * Execute the command
     * 
     * @param array $args Command arguments
     * @return int Exit code
     */
    public function execute(array $args = []): int
    {
        // Show help if requested
        if (in_array('-h', $args) || in_array('--help', $args)) {
            $this->line($this->getHelp());
            return Command::SUCCESS;
        }
        
        // Parse limit option
        $limit = 50; // Default limit
        foreach ($args as $arg) {
            if (strpos($arg, '--limit=') === 0) {
                $limit = (int)substr($arg, 8);
                break;
            }
        }
        
        try {
            $this->info("Processing notification retries (limit: $limit)...");
            
            // Ensure the retry queue table exists
            $this->retryService->ensureRetryQueueTableExists();
            
            // Process due retries using the retry service
            $results = $this->retryService->processDueRetries($limit, $this->notificationService);
            
            // Display results
            $this->success("Notification retry processing completed:");
            $this->line("- Processed: {$results['processed']}");
            $this->line("- Successful: {$results['successful']}");
            $this->line("- Failed: {$results['failed']}");
            $this->line("- Removed: {$results['removed']}");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to process notification retries: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Handler method for scheduler integration
     *
     * Acts as a bridge between the scheduler and the command's execute method.
     * This allows the command to be used both via CLI and as a scheduled job.
     *
     * @param array $parameters Parameters from the scheduler
     * @return mixed Result of execution
     */
    public function handle(array $parameters = []): mixed
    {
        // Convert scheduler parameters to command arguments format
        $args = [];
        
        // Add limit parameter if provided
        if (isset($parameters['--limit'])) {
            $args[] = '--limit=' . $parameters['--limit'];
        }
        
        // Execute the command and return the result
        return $this->execute($args);
    }
}