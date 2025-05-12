<?php

declare(strict_types=1);

namespace Glueful\Notifications\Services;

use DateTime;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Glueful\Repository\NotificationRepository;

/**
 * Notification Retry Service
 *
 * Provides centralized functionality for handling failed notification retries:
 * - Manages retry queue table
 * - Handles scheduling of retries with different backoff strategies
 * - Provides consistent retry behavior across notification channels
 *
 * @package Glueful\Notifications\Services
 */
class NotificationRetryService
{
    /**
     * @var QueryBuilder|null Database query builder
     */
    private ?QueryBuilder $queryBuilder;

    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;

    /**
     * @var array Configuration options
     */
    private array $config;

    /**
     * @var NotificationRepository|null Notification repository
     */
    private ?NotificationRepository $notificationRepository;

    /**
     * NotificationRetryService constructor
     *
     * @param LogManager|null $logger Logger instance
     * @param NotificationRepository|null $notificationRepository Notification repository
     * @param array $config Configuration options
     */
    public function __construct(
        ?LogManager $logger = null,
        ?NotificationRepository $notificationRepository = null,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->notificationRepository = $notificationRepository ?? new NotificationRepository();
        $this->queryBuilder = null; // Lazy initialization
        $this->config = $config;
    }

    /**
     * Initialize the database connection
     *
     * @return void
     */
    private function initDatabase(): void
    {
        if ($this->queryBuilder === null) {
            $connection = new Connection();
            $this->queryBuilder = new QueryBuilder(
                $connection->getPDO(),
                $connection->getDriver()
            );
        }
    }

    /**
     * Queue a notification for retry
     *
     * @param Notification $notification The notification object
     * @param Notifiable $notifiable The notifiable entity
     * @param string $channel The channel that failed
     * @return bool Success status
     */
    public function queueForRetry(Notification $notification, Notifiable $notifiable, string $channel): bool
    {
        // Get current retry count from notification data
        $data = $notification->getData() ?? [];
        $retryCount = ($data['retry_count'] ?? 0) + 1;

        // Update retry count in notification data
        $data['retry_count'] = $retryCount;
        $notification->setData($data);

        // Save the updated notification to persist retry count
        try {
            $this->notificationRepository->save($notification);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Failed to update notification retry count', [
                    'notification_id' => $notification->getId(),
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }

        // Calculate next retry time based on backoff strategy
        $delay = $this->calculateRetryDelay($retryCount);
        $nextRetryTime = new DateTime();
        $nextRetryTime->modify("+{$delay} seconds");

        // Insert into retry queue table
        try {
            // Initialize QueryBuilder if not already done
            $this->initDatabase();

            // Create retry queue table if it doesn't exist
            $this->ensureRetryQueueTableExists();

            // Check if this notification is already in the retry queue
            $existingEntry = $this->queryBuilder->select(
                'notification_retry_queue',
                ['id'],
                [
                    'notification_id' => $notification->getId(),
                    'channel' => $channel
                ]
            )->get();

            if (empty($existingEntry)) {
                // Insert new record
                $this->queryBuilder->insert('notification_retry_queue', [
                    'notification_id' => $notification->getId(),
                    'notifiable_type' => $notifiable->getNotifiableType(),
                    'notifiable_id' => $notifiable->getNotifiableId(),
                    'channel' => $channel,
                    'retry_count' => $retryCount,
                    'retry_at' => $nextRetryTime->format('Y-m-d H:i:s'),
                    'created_at' => (new DateTime())->format('Y-m-d H:i:s')
                ]);
            } else {
                // Update existing record using upsert
                $this->queryBuilder->upsert(
                    'notification_retry_queue',
                    [[
                        'id' => $existingEntry[0]['id'],
                        'notification_id' => $notification->getId(),
                        'notifiable_type' => $notifiable->getNotifiableType(),
                        'notifiable_id' => $notifiable->getNotifiableId(),
                        'channel' => $channel,
                        'retry_count' => $retryCount,
                        'retry_at' => $nextRetryTime->format('Y-m-d H:i:s'),
                        'updated_at' => (new DateTime())->format('Y-m-d H:i:s')
                    ]],
                    ['retry_count', 'retry_at', 'updated_at']
                );
            }

            // Log retry information
            if ($this->logger) {
                $this->logger->info("{$channel} notification queued for retry", [
                    'notification_id' => $notification->getId(),
                    'recipient' => $notifiable->getNotifiableId(),
                    'attempt' => $retryCount,
                    'next_retry' => $nextRetryTime->format('Y-m-d H:i:s')
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Failed to queue notification for retry", [
                    'notification_id' => $notification->getId(),
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }

    /**
     * Calculate retry delay based on retry count and backoff strategy
     *
     * @param int $retryCount Current retry count
     * @return int Delay in seconds before next retry
     */
    public function calculateRetryDelay(int $retryCount): int
    {
        $baseDelay = $this->config['retry']['delay'] ?? 300; // Default: 5 minutes
        $backoffStrategy = $this->config['retry']['backoff'] ?? 'exponential';

        switch ($backoffStrategy) {
            case 'linear':
                // Linear backoff: baseDelay * retryCount
                return $baseDelay * $retryCount;

            case 'exponential':
                // Exponential backoff: baseDelay * (2^retryCount)
                return $baseDelay * (2 ** ($retryCount - 1));

            default:
                // Default to fixed delay
                return $baseDelay;
        }
    }

    /**
     * Ensure the retry queue table exists
     *
     * @return void
     */
    public function ensureRetryQueueTableExists(): void
    {
        try {
            $connection = new Connection();
            $schema = $connection->getSchemaManager();

            // Check if table exists first
            $tables = $schema->getTables();
            if (!in_array('notification_retry_queue', $tables)) {
                // Create table using SchemaManager
                $schema->createTable('notification_retry_queue', [
                    'id' => ['type' => 'INT', 'auto_increment' => true, 'primary' => true],
                    'notification_id' => ['type' => 'VARCHAR(255)', 'null' => false],
                    'notifiable_type' => ['type' => 'VARCHAR(100)', 'null' => false],
                    'notifiable_id' => ['type' => 'VARCHAR(255)', 'null' => false],
                    'channel' => ['type' => 'VARCHAR(50)', 'null' => false],
                    'retry_count' => ['type' => 'INT', 'null' => false, 'default' => 1],
                    'retry_at' => ['type' => 'TIMESTAMP', 'null' => false],
                    'created_at' => ['type' => 'TIMESTAMP', 'null' => false, 'default' => 'CURRENT_TIMESTAMP'],
                    'updated_at' => ['type' => 'TIMESTAMP', 'null' => true, 'on_update' => 'CURRENT_TIMESTAMP']
                ]);

                // Add indexes
                $schema->addIndex([
                    'type' => 'UNIQUE',
                    'columns' => ['notification_id', 'channel'],
                    'table' => 'notification_retry_queue'
                ]);

                $schema->addIndex([
                    'columns' => ['retry_at'],
                    'table' => 'notification_retry_queue'
                ]);

                $schema->addIndex([
                    'columns' => ['notification_id'],
                    'table' => 'notification_retry_queue'
                ]);

                $schema->addIndex([
                    'columns' => ['notifiable_type', 'notifiable_id'],
                    'table' => 'notification_retry_queue'
                ]);
            }
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Failed to create notification_retry_queue table', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Process due retry attempts
     *
     * @param int $limit Maximum number of retries to process at once
     * @param NotificationService $notificationService Notification service to use for sending
     * @return array Statistics about processed retries
     */
    public function processDueRetries(int $limit, NotificationService $notificationService): array
    {
        $this->initDatabase();
        $now = (new DateTime())->format('Y-m-d H:i:s');

        // Get due retries
        $dueRetries = $this->queryBuilder->select(
            'notification_retry_queue',
            ['*'],
            [
                'retry_at <= ?' => $now
            ]
        )
        ->orderBy(['retry_count' => 'ASC', 'retry_at' => 'ASC'])
        ->limit($limit)
        ->get();

        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'removed' => 0
        ];

        if (empty($dueRetries)) {
            return $results;
        }

        foreach ($dueRetries as $retry) {
            $results['processed']++;

            // Get the notification
            $notification = $this->notificationRepository->findByUuId($retry['notification_id']);
            if (!$notification) {
                // Remove from queue if notification doesn't exist anymore
                $this->queryBuilder->delete('notification_retry_queue', ['id' => $retry['id']]);
                $results['removed']++;
                continue;
            }

            // Create notifiable entity directly since getNotifiableEntity is protected
            $notifiable = $this->createNotifiableEntity(
                $retry['notifiable_type'],
                $retry['notifiable_id']
            );

            if (!$notifiable) {
                // Remove from queue if notifiable can't be created
                $this->queryBuilder->delete('notification_retry_queue', ['id' => $retry['id']]);
                $results['removed']++;
                continue;
            }

            // Try to send again with explicitly specified channel
            $result = $notificationService->getDispatcher()->send(
                $notification,
                $notifiable,
                [$retry['channel']]
            );

            if ($result['status'] === 'success') {
                // Mark as sent and remove from retry queue
                $notification->markAsSent();
                $this->notificationRepository->save($notification);
                $this->queryBuilder->delete('notification_retry_queue', ['id' => $retry['id']]);
                $results['successful']++;

                if ($this->logger) {
                    $this->logger->info("Retry successful for notification", [
                        'notification_id' => $notification->getId(),
                        'channel' => $retry['channel'],
                        'attempt' => $retry['retry_count']
                    ]);
                }
            } else {
                // Check if max retries reached
                $maxRetries = $this->config['retry']['max_attempts'] ?? 3;

                if ($retry['retry_count'] >= $maxRetries) {
                    // Max retries reached, remove from queue
                    $this->queryBuilder->delete('notification_retry_queue', ['id' => $retry['id']]);
                    $results['removed']++;

                    if ($this->logger) {
                        $this->logger->warning("Max retries reached for notification", [
                            'notification_id' => $notification->getId(),
                            'channel' => $retry['channel'],
                            'max_retries' => $maxRetries
                        ]);
                    }
                } else {
                    // Queue for another retry
                    $this->queueForRetry($notification, $notifiable, $retry['channel']);
                    $results['failed']++;
                }
            }
        }

        return $results;
    }

    /**
     * Create a notifiable entity from type and ID
     *
     * @param string $type Entity type (e.g., 'user')
     * @param string $id Entity ID
     * @return \Glueful\Notifications\Contracts\Notifiable|null
     */
    private function createNotifiableEntity(string $type, string $id): ?\Glueful\Notifications\Contracts\Notifiable
    {
        // Simple implementation for common entity types
        switch (strtolower($type)) {
            case 'user':
                // For users, create a basic Notifiable implementation
                return new class ($id) implements \Glueful\Notifications\Contracts\Notifiable {
                    private string $id;

                    public function __construct(string $id)
                    {
                        $this->id = $id;
                    }

                    public function routeNotificationFor(string $channel)
                    {
                        return null; // Would need to fetch user data to get actual routing info
                    }

                    public function getNotifiableId(): string
                    {
                        return $this->id;
                    }

                    public function getNotifiableType(): string
                    {
                        return 'user';
                    }

                    public function shouldReceiveNotification(string $notificationType, string $channel): bool
                    {
                        return true; // Default to allowing notifications for retries
                    }

                    public function getNotificationPreferences(): array
                    {
                        return []; // Empty preferences for retries
                    }
                };

            // Add more cases for other entity types as needed

            default:
                // For unknown types, log and return null
                if ($this->logger) {
                    $this->logger->warning("Unknown notifiable entity type: {$type}", [
                        'type' => $type,
                        'id' => $id
                    ]);
                }
                return null;
        }
    }

    /**
     * Check if a notification should be retried
     *
     * @param Notification $notification The notification to check
     * @return bool True if the notification should be retried
     */
    public function shouldRetry(Notification $notification): bool
    {
        // Check if notification has exceeded max retries
        $data = $notification->getData() ?? [];
        $attempts = $data['retry_count'] ?? 0;
        $maxRetries = $this->config['retry']['max_attempts'] ?? 3;

        return $attempts < $maxRetries;
    }

    /**
     * Get the configuration
     *
     * @return array Configuration options
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set configuration option
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }
}
