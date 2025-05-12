<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification\Listeners;

use Glueful\Events\EventListener;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Events\NotificationFailed;
use Glueful\Notifications\Events\NotificationSent;
use Glueful\Notifications\Services\NotificationRetryService;
use Glueful\Notifications\Services\NotificationMetricsService;

/**
 * Email Notification Listener
 *
 * Listens for notification events related to email notifications
 * and handles additional processing or logging for email events.
 *
 * @package Glueful\Extensions\EmailNotification\Listeners
 */
class EmailNotificationListener implements EventListener
{
    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;

    /**
     * @var array Configuration options
     */
    private array $config;

    /**
     * @var NotificationRetryService|null Notification retry service
     */
    private ?NotificationRetryService $retryService;

    /**
     * @var NotificationMetricsService Notification metrics service
     */
    private NotificationMetricsService $metricsService;

    /**
     * EmailNotificationListener constructor
     *
     * @param LogManager|null $logger Logger instance
     * @param array $config Configuration options
     * @param NotificationRetryService|null $retryService Notification retry service
     * @param NotificationMetricsService|null $metricsService Metrics service
     */
    public function __construct(
        ?LogManager $logger = null,
        array $config = [],
        ?NotificationRetryService $retryService = null,
        ?NotificationMetricsService $metricsService = null
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->retryService = $retryService;

        // Create the retry service if not provided
        if ($this->retryService === null) {
            $this->retryService = new NotificationRetryService($logger, null, $config);
        }

        // Create the metrics service if not provided
        $this->metricsService = $metricsService ?? new NotificationMetricsService($logger);
    }

    /**
     * Get the events that the listener should handle
     *
     * @return array Array of event names or patterns
     */
    public function getSubscribedEvents(): array
    {
        return [
            'notification.sent',
            'notification.failed'
        ];
    }

    /**
     * Handle an event
     *
     * @param object $event The event object
     * @return void
     */
    public function handle(object $event)
    {
        // Only process events for the email channel
        if ($event->getChannel() !== 'email') {
            return;
        }

        if ($event instanceof NotificationSent) {
            $this->handleSentEvent($event);
        } elseif ($event instanceof NotificationFailed) {
            $this->handleFailedEvent($event);
        }
    }

    /**
     * Handle a notification sent event
     *
     * @param NotificationSent $event The sent event
     * @return void
     */
    protected function handleSentEvent(NotificationSent $event): void
    {
        $notification = $event->getNotification();
        $notifiable = $event->getNotifiable();
        $notificationUuid = $notification->getUuid();

        // Get recipient email if available
        $recipientEmail = $notifiable->routeNotificationFor('email');

        // Get retry count from metrics service
        $retryCount = $this->metricsService->getRetryCount($notificationUuid, 'email');

        // Calculate delivery time
        $deliveryTime = null;
        $creationTime = $this->metricsService->getNotificationCreationTime($notificationUuid, 'email');

        if ($creationTime) {
            $sentTime = $event->getSentAt()->getTimestamp();
            $deliveryTime = $sentTime - $creationTime;

            // Record delivery time
            $this->metricsService->trackDeliveryTime($notificationUuid, 'email', $deliveryTime);
        }

        // Update success metrics
        $this->metricsService->updateSuccessRateMetrics('email', true);

        // Enhanced logging with delivery metrics
        if ($this->logger && $this->config['logging']['enabled'] ?? true) {
            $this->logger->info('Email notification sent successfully', [
                'notification_uuid' => $notificationUuid,
                'notification_type' => $notification->getType(),
                'recipient' => $recipientEmail ?: $notifiable->getNotifiableId(),
                'subject' => $notification->getData()['subject'] ?? 'No subject',
                'sent_at' => $event->getSentAt()->format('Y-m-d H:i:s'),
                'delivery_time_ms' => $deliveryTime !== null ? $deliveryTime * 1000 : null,
                'retry_count' => $retryCount,
            ]);
        }

        // Clean up performance tracking data for this notification
        $this->metricsService->cleanupNotificationMetrics($notificationUuid, 'email');
    }

    /**
     * Handle a notification failed event
     *
     * @param NotificationFailed $event The failed event
     * @return void
     */
    protected function handleFailedEvent(NotificationFailed $event): void
    {
        $notification = $event->getNotification();
        $notifiable = $event->getNotifiable();
        $reason = $event->getReason();
        $exception = $event->getException();
        $notificationUuid = $notification->getUuid();

        // Get recipient email if available
        $recipientEmail = $notifiable->routeNotificationFor('email');

        // Update retry count
        $retryCount = $this->metricsService->incrementRetryCount($notificationUuid, 'email');

        // Update failure metrics if all retries exhausted
        $maxRetries = $this->config['retry']['max_attempts'] ?? 3;
        if ($retryCount >= $maxRetries) {
            $this->metricsService->updateSuccessRateMetrics('email', false);
        }

        // Enhanced error logging with detailed information
        if ($this->logger && $this->config['logging']['enabled'] ?? true) {
            $this->logger->error('Email notification failed', [
                'notification_uuid' => $notificationUuid,
                'notification_type' => $notification->getType(),
                'recipient' => $recipientEmail ?: $notifiable->getNotifiableId(),
                'reason' => $reason,
                'error_message' => $exception ? $exception->getMessage() : null,
                'failed_at' => $event->getFailedAt()->format('Y-m-d H:i:s'),
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
            ]);
        }

        // Check if retry is enabled in configuration
        if ($this->config['retry']['enabled'] ?? true) {
            // Store notification creation time if this is the first attempt
            if ($retryCount === 1) {
                $this->metricsService->setNotificationCreationTime($notificationUuid, 'email');
            }

            // Delegate retry logic to the NotificationRetryService
            if ($this->retryService->shouldRetry($notification)) {
                $this->retryService->queueForRetry($notification, $notifiable, 'email');
            } elseif ($retryCount >= $maxRetries) {
                // Cleanup metrics data if we've reached max retries and won't try again
                $this->metricsService->cleanupNotificationMetrics($notificationUuid, 'email');
            }
        }
    }
}
