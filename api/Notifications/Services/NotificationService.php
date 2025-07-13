<?php

declare(strict_types=1);

namespace Glueful\Notifications\Services;

use DateTime;
use Glueful\Helpers\Utils;
use Glueful\Extensions\ExtensionManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Templates\TemplateManager;
use Glueful\Repository\NotificationRepository;
use InvalidArgumentException;
use Glueful\Logging\LogManager;
use Glueful\Config\ConfigurableInterface;
use Glueful\Config\ConfigurableTrait;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Notification Service
 *
 * Main service for notification operations, providing a simplified interface
 * for creating, sending, and managing notifications.
 *
 * @package Glueful\Notifications\Services
 */
class NotificationService implements ConfigurableInterface
{
    use ConfigurableTrait;

    /**
     * @var NotificationDispatcher The notification dispatcher
     */
    private NotificationDispatcher $dispatcher;

    /**
     * @var TemplateManager|null The template manager
     */
    private ?TemplateManager $templateManager;

    /**
     * @var NotificationRepository The notification repository
     */
    private NotificationRepository $repository;

    /**
     * @var NotificationMetricsService The metrics service
     */
    private NotificationMetricsService $metricsService;

    /**
     * @var callable Generator for notification IDs
     */
    private $idGenerator;


    /**
     * NotificationService constructor
     *
     * @param NotificationDispatcher $dispatcher Notification dispatcher
     * @param NotificationRepository $repository Notification repository
     * @param TemplateManager|null $templateManager Template manager
     * @param NotificationMetricsService|null $metricsService Metrics service
     * @param array $config Configuration options
     */
    public function __construct(
        NotificationDispatcher $dispatcher,
        NotificationRepository $repository,
        ?TemplateManager $templateManager = null,
        ?NotificationMetricsService $metricsService = null,
        array $config = []
    ) {
        $this->dispatcher = $dispatcher;
        $this->repository = $repository;
        $this->templateManager = $templateManager;

        // Resolve and validate configuration
        $this->resolveOptions($config);

        // Create metrics service if not provided
        $this->metricsService = $metricsService ?? new NotificationMetricsService(
            $dispatcher->getLogger() instanceof LogManager ? $dispatcher->getLogger() : null
        );

        // Set ID generator from validated config
        $this->idGenerator = $this->getOption('id_generator');
    }

    /**
     * Create and send a notification
     *
     * @param string $type Notification type
     * @param Notifiable $notifiable Recipient of the notification
     * @param string $subject Subject of the notification
     * @param array $data Additional notification data
     * @param array $options Additional options (channels, priority, schedule)
     * @return array Result of the send operation
     */
    public function send(
        string $type,
        Notifiable $notifiable,
        string $subject,
        array $data = [],
        array $options = []
    ): array {
        // Create the notification
        $notification = $this->create($type, $notifiable, $subject, $data, $options);
        // Save to database first
        $this->repository->save($notification);

        // Track notification creation time for metrics
        $channels = $options['channels'] ?? $this->getDefaultChannels();
        foreach ($channels as $channel) {
            $this->metricsService->setNotificationCreationTime($notification->getUuid(), $channel);
        }

        // Send it immediately unless scheduled for later
        if (!isset($options['schedule']) || $options['schedule'] == null) {
            $result = $this->dispatcher->send(
                $notification,
                $notifiable,
                $options['channels'] ?? null
            );

            // Update notification in database after sending
            if ($result['status'] === 'success') {
                $notification->markAsSent();
                $this->repository->save($notification);

                // Track successful delivery metrics for each channel
                if (isset($result['channels'])) {
                    foreach ($result['channels'] as $channel => $channelResult) {
                        if ($channelResult['status'] === 'success') {
                            // Calculate delivery time if applicable
                            $creationTime = $this->metricsService->getNotificationCreationTime(
                                $notification->getUuid(),
                                $channel
                            );
                            if ($creationTime) {
                                $deliveryTime = time() - $creationTime;
                                $this->metricsService->trackDeliveryTime(
                                    $notification->getUuid(),
                                    $channel,
                                    $deliveryTime
                                );
                            }

                            // Update success metrics
                            $this->metricsService->updateSuccessRateMetrics($channel, true);

                            // Clean up individual notification metrics
                            $this->metricsService->cleanupNotificationMetrics($notification->getUuid(), $channel);
                        }
                    }
                }
            }

            return $result;
        }

        return [
            'status' => 'scheduled',
            'notification_id' => $notification->getId(),
            'scheduled_at' => $notification->getScheduledAt()->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Create a notification without sending it
     *
     * @param string $type Notification type
     * @param Notifiable $notifiable Recipient of the notification
     * @param string $subject Subject of the notification
     * @param array $data Additional notification data
     * @param array $options Additional options (priority, schedule)
     * @return Notification The created notification
     */
    public function create(
        string $type,
        Notifiable $notifiable,
        string $subject,
        array $data = [],
        array $options = []
    ): Notification {
        // Generate a unique ID
        // $id = is_callable($this->idGenerator)
        //     ? call_user_func($this->idGenerator)
        //     : uniqid('notification_');

        // Generate a UUID if not provided in options
        $uuid = $options['uuid'] ?? Utils::generateNanoID();

        $notification = new Notification(
            $type,
            $subject,
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $data,
            $uuid
        );

        // Set priority if specified
        if (isset($options['priority'])) {
            $notification->setPriority($options['priority']);
        }

        // Schedule for later if specified
        if (isset($options['schedule']) && $options['schedule'] instanceof DateTime) {
            $notification->schedule($options['schedule']);
        }

        return $notification;
    }

    /**
     * Send a notification using a template
     *
     * @param string $type Notification type
     * @param Notifiable $notifiable Recipient of the notification
     * @param string $templateName Template name
     * @param array $templateData Data for template rendering
     * @param array $options Additional options (channels, priority, schedule)
     * @return array Result of the send operation
     * @throws InvalidArgumentException If template manager is not available or template not found
     */
    public function sendWithTemplate(
        string $type,
        Notifiable $notifiable,
        string $templateName,
        array $templateData = [],
        array $options = []
    ): array {
        if ($this->templateManager === null) {
            throw new InvalidArgumentException('Template manager is not available.');
        }

        // Get the template
        $templateMap = $this->templateManager->resolveTemplates($type, $templateName);

        if (empty($templateMap)) {
            throw new InvalidArgumentException("No templates found for type '{$type}' and name '{$templateName}'.");
        }

        // Set subject from template if not provided
        if (!isset($options['subject']) && isset($templateData['subject'])) {
            $options['subject'] = $templateData['subject'];
        } elseif (!isset($options['subject'])) {
            // Default subject based on notification type
            $options['subject'] = ucfirst(str_replace('_', ' ', $type));
        }

        // Create notification
        $notification = $this->create(
            $type,
            $notifiable,
            $options['subject'],
            ['template_data' => $templateData, 'template_name' => $templateName],
            $options
        );

        // Save to database
        $this->repository->save($notification);

        // Send notification
        $result = $this->dispatcher->send(
            $notification,
            $notifiable,
            $options['channels'] ?? null
        );

        // Update notification in database after sending
        if ($result['status'] === 'success') {
            $notification->markAsSent();
            $this->repository->save($notification);
        }

        return $result;
    }

    /**
     * Mark a notification as read
     *
     * @param Notification $notification The notification to mark as read
     * @param DateTime|null $readAt When the notification was read (null for current time)
     * @return Notification The updated notification
     */
    public function markAsRead(Notification $notification, ?DateTime $readAt = null): Notification
    {
        $notification = $notification->markAsRead($readAt);
        $this->repository->save($notification);
        return $notification;
    }

    /**
     * Mark a notification as unread
     *
     * @param Notification $notification The notification to mark as unread
     * @return Notification The updated notification
     */
    public function markAsUnread(Notification $notification): Notification
    {
        $notification = $notification->markAsUnread();
        $this->repository->save($notification);
        return $notification;
    }

    /**
     * Set user preference for a notification type
     *
     * @param Notifiable $notifiable The user or entity
     * @param string $notificationType Notification type
     * @param array|null $channels Preferred channels (null to use defaults)
     * @param bool $enabled Whether notifications are enabled
     * @param array|null $settings Additional settings
     * @param string|null $uuid UUID for cross-system identification
     * @return NotificationPreference The created/updated preference
     */
    public function setPreference(
        Notifiable $notifiable,
        string $notificationType,
        ?array $channels = null,
        bool $enabled = true,
        ?array $settings = null,
        ?string $uuid = null
    ): NotificationPreference {
        // Generate a unique ID
        $id = is_callable($this->idGenerator)
            ? call_user_func($this->idGenerator)
            : uniqid('preference_');

        // Generate a UUID if not provided
        if (!$uuid) {
            $uuid = Utils::generateNanoID();
        }

        $preference = new NotificationPreference(
            $id,
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $notificationType,
            $channels,
            $enabled,
            $settings,
            $uuid
        );

        // Save to database
        $this->repository->savePreference($preference);

        return $preference;
    }

    /**
     * Get notifications for a user with optional filtering
     *
     * @param Notifiable $notifiable Recipient
     * @param bool $onlyUnread Whether to get only unread notifications
     * @param int|null $limit Maximum number of notifications
     * @param int|null $offset Pagination offset
     * @param array $filters Optional additional filters (type, priority, date range)
     * @return array Array of Notification objects
     */
    public function getNotifications(
        Notifiable $notifiable,
        bool $onlyUnread = false,
        ?int $limit = null,
        ?int $offset = null,
        array $filters = []
    ): array {
        return $this->repository->findForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $onlyUnread,
            $limit,
            $offset,
            $filters
        );
    }

    /**
     * Count total notifications for a user with optional filters
     *
     * @param Notifiable $notifiable Recipient
     * @param bool $onlyUnread Whether to count only unread notifications
     * @param array $filters Additional filters to apply
     * @return int Total count of notifications
     */
    public function countNotifications(
        Notifiable $notifiable,
        bool $onlyUnread = false,
        array $filters = []
    ): int {
        return $this->repository->countForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            $onlyUnread,
            $filters
        );
    }

    /**
     * Get notification by UUID
     *
     * @param string $uuid Notification UUID
     * @return Notification|null The notification or null if not found
     */
    public function getNotificationByUuid(string $uuid): ?Notification
    {
        return $this->repository->findByUuid($uuid);
    }

    /**
     * Get unread notification count for a user
     *
     * @param Notifiable $notifiable Recipient
     * @return int Count of unread notifications
     */
    public function getUnreadCount(Notifiable $notifiable): int
    {
        // Using the countForNotifiable method with onlyUnread=true instead of countUnread
        return $this->repository->countForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId(),
            true // onlyUnread=true to count only unread notifications
        );
    }

    /**
     * Mark all notifications as read for a user
     *
     * @param Notifiable $notifiable Recipient
     * @return int Number of notifications updated
     */
    public function markAllAsRead(Notifiable $notifiable): int
    {
        return $this->repository->markAllAsRead(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId()
        );
    }

    /**
     * Process scheduled notifications
     *
     * This method should be called from a cron job or scheduler
     * to process notifications that were scheduled for delivery.
     *
     * @param int $batchSize Maximum number of notifications to process
     * @return array Processing results
     */
    public function processScheduledNotifications(int $batchSize = 50): array
    {
        $pendingNotifications = $this->repository->findPendingScheduled(null, $batchSize);

        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0
        ];

        foreach ($pendingNotifications as $notification) {
            $results['processed']++;

            // Get notifiable entity
            $notifiableType = $notification->getNotifiableType();
            $notifiableId = $notification->getNotifiableId();

            // This assumes you have a way to get the notifiable entity
            // You would need to implement this method or similar
            $notifiable = $this->getNotifiableEntity($notifiableType, $notifiableId);

            if (!$notifiable) {
                $results['failed']++;
                continue;
            }

            $sendResult = $this->dispatcher->send($notification, $notifiable);

            if ($sendResult['status'] === 'success') {
                $notification->markAsSent();
                $this->repository->save($notification);
                $results['successful']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Delete old notifications
     *
     * @param int $olderThanDays Delete notifications older than this many days
     * @return bool Success status
     */
    public function deleteOldNotifications(int $olderThanDays): bool
    {
        return $this->repository->deleteOldNotifications($olderThanDays);
    }

    /**
     * Get notification preferences for a user
     *
     * @param Notifiable $notifiable The user or entity
     * @return array Array of NotificationPreference objects
     */
    public function getPreferences(Notifiable $notifiable): array
    {
        return $this->repository->findPreferencesForNotifiable(
            $notifiable->getNotifiableType(),
            $notifiable->getNotifiableId()
        );
    }

    /**
     * Set a notification ID generator
     *
     * @param callable $generator Function that generates unique IDs
     * @return self
     */
    public function setIdGenerator(callable $generator): self
    {
        $this->idGenerator = $generator;
        return $this;
    }

    /**
     * Get the notification dispatcher
     *
     * @return NotificationDispatcher The notification dispatcher
     */
    public function getDispatcher(): NotificationDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Get the notification repository
     *
     * @return NotificationRepository The notification repository
     */
    public function getRepository(): NotificationRepository
    {
        return $this->repository;
    }

    /**
     * Get the template manager
     *
     * @return TemplateManager|null The template manager
     */
    public function getTemplateManager(): ?TemplateManager
    {
        return $this->templateManager;
    }

    /**
     * Set the template manager
     *
     * @param TemplateManager $templateManager The template manager
     * @return self
     */
    public function setTemplateManager(TemplateManager $templateManager): self
    {
        $this->templateManager = $templateManager;
        return $this;
    }

    /**
     * Get configuration option
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->getOption($key, $default);
    }

    /**
     * Get the notifiable entity from type and ID
     *
     * Retrieves a notifiable entity based on its type and ID.
     * Supports different entity types like 'user', 'customer', etc.
     *
     * @param string $type Entity type
     * @param string $id Entity ID
     * @return Notifiable|null The notifiable entity
     */
    protected function getNotifiableEntity(string $type, string $id): ?Notifiable
    {
        // Determine which repository to use based on entity type
        switch (strtolower($type)) {
            case 'user':
                // For users, use the UserRepository
                $userRepository = new \Glueful\Repository\UserRepository();
                $userData = $userRepository->findByUuid($id);

                if (!$userData) {
                    return null;
                }

                // Create and return a notifiable object that implements the Notifiable interface
                return new class ($id) implements \Glueful\Notifications\Contracts\Notifiable {
                    private string $uuid;

                    public function __construct(string $uuid)
                    {
                        $this->uuid = $uuid;
                    }

                    public function routeNotificationFor(string $channel)
                    {
                        return null; // Can be extended to return specific routing info based on channel
                    }

                    public function getNotifiableId(): string
                    {
                        return $this->uuid;
                    }

                    public function getNotifiableType(): string
                    {
                        return 'user';
                    }

                    public function shouldReceiveNotification(string $notificationType, string $channel): bool
                    {
                        return true; // Default to allowing notifications
                    }

                    public function getNotificationPreferences(): array
                    {
                        return []; // Can be extended to fetch actual preferences
                    }
                };

            // Add more cases for other entity types as needed
            // case 'customer':
            //    $customerRepository = new CustomerRepository();
            //    ...

            default:
                // Try to resolve through extension system if available
                return $this->resolveNotifiableEntityThroughExtensions($type, $id);
        }
    }

    /**
     * Resolve notifiable entity through extensions
     *
     * Attempts to resolve a notifiable entity using the extension system
     * for entity types not handled directly by this service.
     *
     * @param string $type Entity type
     * @param string $id Entity ID
     * @return Notifiable|null The notifiable entity or null if not found
     */
    protected function resolveNotifiableEntityThroughExtensions(string $type, string $id): ?Notifiable
    {
        // Get all loaded extensions through ExtensionManager
        $extensionManager = container()->get(ExtensionManager::class);
        $extensions = $extensionManager->getLoadedExtensions();

        // Look for extensions that might support this type of notifiable entity
        foreach ($extensions as $extensionClass) {
            // Check if the extension has a method for resolving notifiable entities
            if (method_exists($extensionClass, 'resolveNotifiableEntity')) {
                try {
                    // Call the extension's resolveNotifiableEntity method
                    $notifiable = call_user_func_array([$extensionClass, 'resolveNotifiableEntity'], [$type, $id]);

                    // If the extension returned a valid Notifiable entity, return it
                    if ($notifiable instanceof \Glueful\Notifications\Contracts\Notifiable) {
                        return $notifiable;
                    }
                } catch (\Throwable $e) {
                    // Log error but continue trying other extensions
                    error_log("Extension {$extensionClass} failed to resolve notifiable entity: " . $e->getMessage());
                }
            }
        }

        // If no extension could resolve the entity, try notification extensions
        if (isset($this->dispatcher)) {
            foreach ($this->dispatcher->getExtensions() as $extension) {
                // Check if notification extension supports resolving notifiable entities
                if (method_exists($extension, 'resolveNotifiableEntity')) {
                    try {
                        $notifiable = $extension->resolveNotifiableEntity($type, $id);
                        if ($notifiable instanceof \Glueful\Notifications\Contracts\Notifiable) {
                            return $notifiable;
                        }
                    } catch (\Throwable $e) {
                        // Log error but continue trying other extensions
                        error_log("Notification extension failed to resolve notifiable entity: " . $e->getMessage());
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get the metrics service
     *
     * @return NotificationMetricsService The metrics service
     */
    public function getMetricsService(): NotificationMetricsService
    {
        return $this->metricsService;
    }

    /**
     * Set the metrics service
     *
     * @param NotificationMetricsService $metricsService The metrics service
     * @return self
     */
    public function setMetricsService(NotificationMetricsService $metricsService): self
    {
        $this->metricsService = $metricsService;
        return $this;
    }

    /**
     * Get notification performance metrics for all channels
     *
     * @return array Performance metrics for all channels
     */
    public function getPerformanceMetrics(): array
    {
        // Get all active channels
        $channels = $this->dispatcher->getChannelManager()->getAvailableChannels();

        // Get metrics for all channels
        return $this->metricsService->getAllMetrics($channels);
    }

    /**
     * Get metrics for a specific channel
     *
     * @param string $channel Channel name
     * @return array Channel-specific metrics
     */
    public function getChannelMetrics(string $channel): array
    {
        return $this->metricsService->getChannelMetrics($channel);
    }

    /**
     * Reset metrics for a specific channel
     *
     * @param string $channel Channel name
     * @return bool Success status
     */
    public function resetChannelMetrics(string $channel): bool
    {
        return $this->metricsService->resetChannelMetrics($channel);
    }

    /**
     * Get default notification channels
     *
     * @return array Array of default channel names
     */
    protected function getDefaultChannels(): array
    {
        return $this->getOption('default_channels');
    }

    /**
     * Configure notification service options
     *
     * @param OptionsResolver $resolver Options resolver instance
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'id_generator' => function () {
                return Utils::generateNanoID();
            },
            'default_channels' => ['database'],
            'max_retry_attempts' => 3,
            'retry_delay_seconds' => 60,
            'batch_size' => 100,
            'rate_limit_per_minute' => 1000,
            'enable_analytics' => true,
            'template_cache_ttl' => 3600,
        ]);

        $resolver->setAllowedTypes('id_generator', 'callable');
        $resolver->setAllowedTypes('default_channels', 'array');
        $resolver->setAllowedTypes('max_retry_attempts', 'int');
        $resolver->setAllowedTypes('retry_delay_seconds', 'int');
        $resolver->setAllowedTypes('batch_size', 'int');
        $resolver->setAllowedTypes('rate_limit_per_minute', 'int');
        $resolver->setAllowedTypes('enable_analytics', 'bool');
        $resolver->setAllowedTypes('template_cache_ttl', 'int');

        // Validate default channels array
        $resolver->setNormalizer('default_channels', function ($options, $value) {
            unset($options); // Required by interface but not used
            if (empty($value)) {
                throw new \InvalidArgumentException('default_channels cannot be empty');
            }

            $validChannels = ['email', 'sms', 'database', 'slack', 'webhook', 'push'];
            foreach ($value as $channel) {
                if (!is_string($channel)) {
                    throw new \InvalidArgumentException('All channel names must be strings');
                }
                if (!in_array($channel, $validChannels)) {
                    throw new \InvalidArgumentException(
                        "Invalid channel '{$channel}'. Valid channels: " . implode(', ', $validChannels)
                    );
                }
            }

            return array_unique($value);
        });

        // Validate retry attempts
        $resolver->setAllowedValues('max_retry_attempts', function ($value) {
            return $value >= 0 && $value <= 10;
        });

        // Validate retry delay
        $resolver->setAllowedValues('retry_delay_seconds', function ($value) {
            return $value >= 1 && $value <= 3600; // 1 second to 1 hour
        });

        // Validate batch size
        $resolver->setAllowedValues('batch_size', function ($value) {
            return $value >= 1 && $value <= 1000;
        });

        // Validate rate limit
        $resolver->setAllowedValues('rate_limit_per_minute', function ($value) {
            return $value >= 1 && $value <= 10000;
        });

        // Validate template cache TTL
        $resolver->setAllowedValues('template_cache_ttl', function ($value) {
            return $value >= 60 && $value <= 86400; // 1 minute to 24 hours
        });

        // Validate ID generator
        $resolver->setNormalizer('id_generator', function ($options, $value) {
            unset($options); // Required by interface but not used
            if (!is_callable($value)) {
                throw new \InvalidArgumentException('id_generator must be callable');
            }

            // Test the generator to ensure it returns a string
            try {
                $testId = $value();
                if (!is_string($testId) || empty($testId)) {
                    throw new \InvalidArgumentException(
                        'id_generator must return a non-empty string'
                    );
                }
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    'id_generator validation failed: ' . $e->getMessage()
                );
            }

            return $value;
        });
    }
}
