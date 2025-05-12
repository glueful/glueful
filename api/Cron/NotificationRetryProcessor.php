<?php

declare(strict_types=1);

namespace Glueful\Cron;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Repository\NotificationRepository;

/**
 * NotificationRetryProcessor
 *
 * Processes pending notification retries.
 * Intended to be run on a schedule from the scheduler.
 *
 * @package Glueful\Cron
 */
class NotificationRetryProcessor
{
    /**
     * @var NotificationRetryService Notification retry service
     */
    private NotificationRetryService $retryService;

    /**
     * @var NotificationService Notification service
     */
    private NotificationService $notificationService;

    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;

    /**
     * Constructor
     *
     * @param NotificationRetryService|null $retryService Notification retry service
     * @param NotificationService|null $notificationService Notification service
     * @param LogManager|null $logger Logger instance
     */
    public function __construct(
        ?NotificationRetryService $retryService = null,
        ?NotificationService $notificationService = null,
        ?LogManager $logger = null
    ) {
        // Create retry service if not provided
        if ($retryService === null) {
            $this->retryService = new NotificationRetryService($logger);
        } else {
            $this->retryService = $retryService;
        }

        // Create notification service if not provided
        if ($notificationService === null) {
            $notificationRepository = new NotificationRepository();
            $channelManager = new ChannelManager();
            $dispatcher = new NotificationDispatcher($channelManager, $logger);
            $this->notificationService = new NotificationService($dispatcher, $notificationRepository);
        } else {
            $this->notificationService = $notificationService;
        }

        $this->logger = $logger;
    }

    /**
     * Handle the scheduled job execution
     *
     * @param array $params Command parameters
     * @return array|bool Result of the execution
     */
    public function handle(array $params = [])
    {
        $limit = $params['limit'] ?? 50;

        // Ensure retry queue table exists
        $this->retryService->ensureRetryQueueTableExists();

        // Process due retries
        $results = $this->retryService->processDueRetries($limit, $this->notificationService);

        // Log the results
        if ($this->logger) {
            $this->logger->info("Notification retry processing completed", [
                'processed' => $results['processed'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'removed' => $results['removed']
            ]);
        }

        return $results;
    }
}
