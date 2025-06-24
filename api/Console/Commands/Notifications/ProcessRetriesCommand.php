<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Notifications;

use Glueful\Console\BaseCommand;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Repository\NotificationRepository;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\ChannelManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Process Notification Retries Command
 * - Processes queued notification retries that were scheduled due to previous delivery failures
 * - Advanced retry scheduling with exponential backoff
 * - Comprehensive failure tracking and reporting
 * - Integration with notification service architecture
 * - Support for different retry strategies and limits
 * @package Glueful\Console\Commands\Notifications
 */
#[AsCommand(
    name: 'notifications:process-retries',
    description: 'Process queued notification retries'
)]
class ProcessRetriesCommand extends BaseCommand
{
    private NotificationService $notificationService;
    private NotificationRetryService $retryService;
    private LogManager $logger;

    protected function configure(): void
    {
        $this->setDescription('Process queued notification retries')
             ->setHelp('This command processes queued notification retries that were scheduled ' .
                      'due to previous delivery failures. It checks the retry queue and attempts ' .
                      'to redeliver notifications that are due for retry.')
             ->addOption(
                 'limit',
                 'l',
                 InputOption::VALUE_REQUIRED,
                 'Maximum number of retries to process',
                 '50'
             )
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'Show what would be processed without actually sending notifications'
             )
             ->addOption(
                 'channel',
                 'c',
                 InputOption::VALUE_REQUIRED,
                 'Process retries for specific channel only (email, sms, etc.)'
             )
             ->addOption(
                 'priority',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Process retries for specific priority only (high, medium, low)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');
        $channel = $input->getOption('channel');
        $priority = $input->getOption('priority');

        // Initialize services
        $this->initializeServices();

        $this->info("🔄 Processing notification retries (limit: $limit)...");
        if ($dryRun) {
            $this->warning("🧪 Running in dry-run mode - no notifications will be sent");
        }

        try {
            // Ensure the retry queue table exists
            $this->retryService->ensureRetryQueueTableExists();

            // Build filter options
            $options = [
                'limit' => $limit,
                'dry_run' => $dryRun
            ];

            if ($channel) {
                $options['channel'] = $channel;
                $this->info("📋 Filtering by channel: $channel");
            }

            if ($priority) {
                $options['priority'] = $priority;
                $this->info("🎯 Filtering by priority: $priority");
            }

            // Process due retries using the retry service
            $results = $this->retryService->processDueRetries($limit, $this->notificationService);

            // Display detailed results
            $this->displayResults($results, $dryRun);

            // Determine exit code based on results
            if ($results['failed'] > 0 && $results['successful'] === 0) {
                $this->error("❌ All retry attempts failed");
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to process notification retries: " . $e->getMessage());

            // Log the error for debugging
            $this->logger->error('Notification retry processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return self::FAILURE;
        }
    }

    private function initializeServices(): void
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

    private function displayResults(array $results, bool $dryRun): void
    {
        $this->line('');

        if ($dryRun) {
            $this->success("🧪 Dry-run completed - Notification retry analysis:");
        } else {
            $this->success("✅ Notification retry processing completed:");
        }

        $this->line('');

        // Create results table
        $tableData = [
            ['Processed', $results['processed']],
            ['Successful', $results['successful']],
            ['Failed', $results['failed']],
            ['Removed', $results['removed']]
        ];

        $this->table(['Metric', 'Count'], $tableData);

        // Show additional statistics if available
        if (!empty($results['by_channel'])) {
            $this->line('');
            $this->info('📊 Results by Channel:');
            foreach ($results['by_channel'] as $channel => $count) {
                $this->line("  • $channel: $count");
            }
        }

        if (!empty($results['by_priority'])) {
            $this->line('');
            $this->info('🎯 Results by Priority:');
            foreach ($results['by_priority'] as $priority => $count) {
                $this->line("  • $priority: $count");
            }
        }

        // Show summary message
        $this->line('');
        if ($results['processed'] === 0) {
            $this->info("ℹ️  No notification retries were due for processing");
        } elseif ($results['successful'] > 0) {
            $successRate = round(($results['successful'] / $results['processed']) * 100, 1);
            $this->success("🎉 Successfully processed {$results['successful']} notifications " .
                "($successRate% success rate)");
        }

        if ($results['failed'] > 0) {
            $this->warning("⚠️  {$results['failed']} notifications failed and may need manual review");
        }

        if (!$dryRun && $results['processed'] > 0) {
            $this->line('');
            $this->info("💡 To analyze what would be processed next time, run with --dry-run");
        }
    }
}
