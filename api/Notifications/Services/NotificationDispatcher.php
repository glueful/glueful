<?php

declare(strict_types=1);

namespace Glueful\Notifications\Services;

use DateTime;
use Glueful\Events\EventDispatcher;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Events\NotificationFailed;
use Glueful\Notifications\Events\NotificationSent;
use Glueful\Notifications\Models\Notification;
use Throwable;

/**
 * Notification Dispatcher Service
 *
 * Handles the sending of notifications through appropriate channels.
 * Responsible for notification delivery, tracking, and status updates.
 *
 * @package Glueful\Notifications\Services
 */
class NotificationDispatcher
{
    /**
     * @var ChannelManager Channel manager instance
     */
    private ChannelManager $channelManager;

    /**
     * @var array Registered notification extensions
     */
    private array $extensions = [];

    /**
     * @var LogManager|null Logger for notification events
     */
    private ?LogManager $logger;

    /**
     * @var EventDispatcher|null Event dispatcher for notification events
     */
    private ?EventDispatcher $eventDispatcher;

    /**
     * @var array Configuration options
     */
    private array $config;

    /**
     * NotificationDispatcher constructor
     *
     * @param ChannelManager $channelManager Channel manager instance
     * @param LogManager|null $logger Logger instance
     * @param EventDispatcher|null $eventDispatcher Event dispatcher instance
     * @param array $config Configuration options
     */
    public function __construct(
        ChannelManager $channelManager,
        ?LogManager $logger = null,
        ?EventDispatcher $eventDispatcher = null,
        array $config = []
    ) {
        $this->channelManager = $channelManager;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->config = $config;
    }

    /**
     * Send a notification through the specified channels
     *
     * @param Notification $notification The notification to send
     * @param Notifiable $notifiable The recipient of the notification
     * @param array|null $channels Specific channels to use (null for default channels)
     * @return array Results of the sending operation per channel
     */
    public function send(Notification $notification, Notifiable $notifiable, ?array $channels = null): array
    {
        // Skip if notification is already sent
        if ($notification->isSent()) {
            $this->log('warning', "Notification {$notification->getId()} has already been sent.", [
                'notification_id' => $notification->getId(),
                'notifiable_id' => $notifiable->getNotifiableId()
            ]);
            return ['status' => 'skipped', 'reason' => 'already_sent'];
        }

        // Skip if notification is scheduled for the future
        $scheduledAt = $notification->getScheduledAt();
        if ($scheduledAt !== null && $scheduledAt > new DateTime()) {
            $this->log('info', "Notification {$notification->getId()} is scheduled for later delivery.", [
                'notification_id' => $notification->getId(),
                'notifiable_id' => $notifiable->getNotifiableId(),
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s')
            ]);
            return ['status' => 'deferred', 'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s')];
        }

        // Determine which channels to use
        $channelsToUse = $this->resolveChannels($notification, $notifiable, $channels);

        if (empty($channelsToUse)) {
            $this->log('warning', "No valid channels available for notification {$notification->getId()}.", [
                'notification_id' => $notification->getId(),
                'notifiable_id' => $notifiable->getNotifiableId()
            ]);
            return ['status' => 'failed', 'reason' => 'no_channels'];
        }

        $results = [];
        $successCount = 0;

        // Send to each channel
        foreach ($channelsToUse as $channelName) {
            try {
                if (!$this->channelManager->hasChannel($channelName)) {
                    $results[$channelName] = [
                        'status' => 'failed',
                        'reason' => 'channel_not_found'
                    ];
                    continue;
                }

                $channel = $this->channelManager->getChannel($channelName);

                // Skip if channel is not available
                if (!$channel->isAvailable()) {
                    $results[$channelName] = [
                        'status' => 'skipped',
                        'reason' => 'channel_unavailable'
                    ];
                    continue;
                }

                // Skip if notifiable should not receive notification on this channel
                if (!$notifiable->shouldReceiveNotification($notification->getType(), $channelName)) {
                    $results[$channelName] = [
                        'status' => 'skipped',
                        'reason' => 'recipient_opted_out'
                    ];
                    continue;
                }

                // Get notification data
                $data = $notification->getData() ?? [];

                // Process notification through extensions
                $data = $this->processBeforeSend($data, $notifiable, $channelName, $notification->getType());

                // Format the notification for this channel
                $formattedData = $channel->format($data, $notifiable);

                // Send the notification
                $success = $channel->send($notifiable, $formattedData);

                if ($success) {
                    $results[$channelName] = [
                        'status' => 'success'
                    ];
                    $successCount++;

                    // Trigger sent event
                    $this->triggerSentEvent($notification, $notifiable, $channelName);
                } else {
                    $results[$channelName] = [
                        'status' => 'failed',
                        'reason' => 'send_failed'
                    ];

                    // Trigger failed event
                    $this->triggerFailedEvent($notification, $notifiable, $channelName, 'send_failed');
                }

                // Process after sending
                $this->processAfterSend($data, $notifiable, $channelName, $success, $notification->getType());
            } catch (Throwable $e) {
                $results[$channelName] = [
                    'status' => 'failed',
                    'reason' => 'exception',
                    'message' => $e->getMessage()
                ];

                $this->log(
                    'error',
                    "Failed to send notification {$notification->getId()} via channel {$channelName}: " .
                    "{$e->getMessage()}",
                    [
                    'notification_id' => $notification->getId(),
                    'notifiable_id' => $notifiable->getNotifiableId(),
                    'channel' => $channelName,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                    ]
                );

                // Trigger failed event
                $this->triggerFailedEvent($notification, $notifiable, $channelName, 'exception', $e);
            }
        }

        // Mark notification as sent if at least one channel succeeded
        if ($successCount > 0) {
            $notification->markAsSent();
        }

        return [
            'status' => $successCount > 0 ? 'success' : 'failed',
            'sent_count' => $successCount,
            'total_channels' => count($channelsToUse),
            'channels' => $results
        ];
    }

    /**
     * Register a notification extension
     *
     * @param NotificationExtension $extension The extension to register
     * @return self
     */
    public function registerExtension(NotificationExtension $extension): self
    {
        $name = $extension->getExtensionName();
        $this->extensions[$name] = $extension;
        return $this;
    }

    /**
     * Get a registered extension
     *
     * @param string $name Extension name
     * @return NotificationExtension|null The extension instance or null if not found
     */
    public function getExtension(string $name): ?NotificationExtension
    {
        return $this->extensions[$name] ?? null;
    }

    /**
     * Remove a registered extension
     *
     * @param string $name Extension name
     * @return self
     */
    public function removeExtension(string $name): self
    {
        if (isset($this->extensions[$name])) {
            unset($this->extensions[$name]);
        }
        return $this;
    }

    /**
     * Get all registered extensions
     *
     * @return array Array of extensions
     */
    public function getExtensions(): array
    {
        return $this->extensions;
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

    /**
     * Get configuration option
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get the channel manager
     *
     * @return ChannelManager The channel manager
     */
    public function getChannelManager(): ChannelManager
    {
        return $this->channelManager;
    }

    /**
     * Set the event dispatcher
     *
     * @param EventDispatcher $eventDispatcher Event dispatcher instance
     * @return self
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;
        return $this;
    }

    /**
     * Get the event dispatcher
     *
     * @return EventDispatcher|null The event dispatcher
     */
    public function getEventDispatcher(): ?EventDispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * Resolve which channels to use for sending a notification
     *
     * @param Notification $notification The notification to send
     * @param Notifiable $notifiable The recipient
     * @param array|null $explicitChannels Explicitly specified channels
     * @return array Channels to use
     */
    protected function resolveChannels(
        Notification $notification,
        Notifiable $notifiable,
        ?array $explicitChannels
    ): array {
        // If channels are explicitly specified, use those
        if ($explicitChannels !== null && !empty($explicitChannels)) {
            return $explicitChannels;
        }

        // Check if the notifiable has preferred channels for this notification type
        $preferences = $notifiable->getNotificationPreferences();
        $notificationType = $notification->getType();

        foreach ($preferences as $preference) {
            if ($preference->getNotificationType() === $notificationType) {
                $preferredChannels = $preference->getChannels();
                if (!empty($preferredChannels)) {
                    return $preferredChannels;
                }
            }
        }

        // Fall back to default channels from config
        $defaultChannels = $this->getConfig('default_channels', []);
        if (!empty($defaultChannels)) {
            return $defaultChannels;
        }

        // As a last resort, use all available channels
        return $this->channelManager->getAvailableChannels();
    }

    /**
     * Process notification data through extensions before sending
     *
     * @param array $data Notification data
     * @param Notifiable $notifiable The recipient
     * @param string $channel The channel being used
     * @param string $notificationType The notification type
     * @return array Processed notification data
     */
    protected function processBeforeSend(
        array $data,
        Notifiable $notifiable,
        string $channel,
        string $notificationType
    ): array {
        foreach ($this->extensions as $extension) {
            if (in_array($notificationType, $extension->getSupportedNotificationTypes())) {
                $data = $extension->beforeSend($data, $notifiable, $channel);
            }
        }

        return $data;
    }

    /**
     * Process notification after sending
     *
     * @param array $data Notification data
     * @param Notifiable $notifiable The recipient
     * @param string $channel The channel used
     * @param bool $success Whether sending was successful
     * @param string $notificationType The notification type
     * @return void
     */
    protected function processAfterSend(
        array $data,
        Notifiable $notifiable,
        string $channel,
        bool $success,
        string $notificationType
    ): void {
        foreach ($this->extensions as $extension) {
            if (in_array($notificationType, $extension->getSupportedNotificationTypes())) {
                $extension->afterSend($data, $notifiable, $channel, $success);
            }
        }
    }

    /**
     * Trigger notification sent event
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The channel used
     * @return void
     */
    protected function triggerSentEvent(Notification $notification, Notifiable $notifiable, string $channel): void
    {
        // Create the event object
        $event = new NotificationSent($notification, $notifiable, $channel);

        // Log the event
        $this->log('info', "Notification {$notification->getId()} sent successfully via {$channel}.", [
            'notification_id' => $notification->getId(),
            'notifiable_id' => $notifiable->getNotifiableId(),
            'channel' => $channel
        ]);

        // Dispatch the event if event dispatcher is available
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Trigger notification failed event
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The channel used
     * @param string $reason Failure reason
     * @param Throwable|null $exception Exception if any
     * @return void
     */
    protected function triggerFailedEvent(
        Notification $notification,
        Notifiable $notifiable,
        string $channel,
        string $reason,
        ?Throwable $exception = null
    ): void {
        // Create the event object
        $event = new NotificationFailed(
            $notification,
            $notifiable,
            $channel,
            $reason,
            $exception
        );

        // Log the event
        $this->log('warning', "Failed to send notification {$notification->getId()} via {$channel}: {$reason}", [
            'notification_id' => $notification->getId(),
            'notifiable_id' => $notifiable->getNotifiableId(),
            'channel' => $channel,
            'reason' => $reason,
            'exception' => $exception ? $exception->getMessage() : null
        ]);

        // Dispatch the event if event dispatcher is available
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch($event);
        }
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Log context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Set the logger
     *
     * @param LogManager $logger Logger instance
     * @return self
     */
    public function setLogger(LogManager $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the logger
     *
     * @return LogManager|null The logger instance
     */
    public function getLogger(): ?LogManager
    {
        return $this->logger;
    }
}
