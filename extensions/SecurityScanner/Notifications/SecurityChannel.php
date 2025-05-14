<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Notifications;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Contracts\Notifiable;

/**
 * Security Channel
 *
 * Handles security notifications and routes them to appropriate destinations
 * based on severity and configuration.
 *
 * @package Glueful\Extensions\SecurityScanner\Notifications
 */
class SecurityChannel implements NotificationChannel
{
    /**
     * @var array Channel configuration
     */
    private array $config;

    /**
     * @var SecurityNotificationFormatter The notification formatter
     */
    private SecurityNotificationFormatter $formatter;

    /**
     * @var LogManager Logger instance
     */
    private LogManager $logger;

    /**
     * Constructor
     *
     * @param array $config Channel configuration
     * @param SecurityNotificationFormatter $formatter Notification formatter
     */
    public function __construct(array $config, SecurityNotificationFormatter $formatter)
    {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->logger = new LogManager('security_channel');
    }

    /**
     * Get the channel name
     *
     * @return string The channel name
     */
    public function getChannelName(): string
    {
        return 'security';
    }

    /**
     * Check if the channel is available for sending notifications
     *
     * @return bool Whether the channel is available
     */
    public function isAvailable(): bool
    {
        // Always available since it's an internal notification mechanism
        return true;
    }

    /**
     * Send a notification
     *
     * @param Notifiable $notifiable The entity receiving the notification
     * @param array $data Notification data
     * @return bool Whether the notification was sent successfully
     */
    public function send(Notifiable $notifiable, array $data): bool
    {
        try {
            // Get the recipient's security notification route
            $recipient = $notifiable->routeNotificationFor('security');
            if (empty($recipient)) {
                $this->logger->warning('No security notification route for notifiable', [
                    'notifiable_class' => get_class($notifiable)
                ]);
                return false;
            }

            // Format the notification
            $formatted = $this->formatter->format($data);

            // Get notification severity
            $severity = $data['severity'] ?? $this->config['default_level'];

            // Check if this severity level is enabled
            if (!in_array($severity, $this->config['notification_levels'])) {
                $this->logger->info('Security notification skipped due to severity level', [
                    'severity' => $severity,
                    'enabled_levels' => $this->config['notification_levels']
                ]);
                return true; // Return true since this is an expected condition
            }

            // Log the notification
            $this->logger->info('Security notification triggered', [
                'type' => $data['type'] ?? 'unknown',
                'severity' => $severity,
                'recipient' => $recipient
            ]);

            // Store notification in the database if storage is enabled
            if (!empty($this->config['store_notifications'])) {
                $this->storeNotification($notifiable, $data, $formatted);
            }

            // Forward to other notification channels based on configuration
            if (!empty($this->config['forward_channels'])) {
                $this->forwardToChannels($notifiable, $data, $formatted);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error sending security notification: ' . $e->getMessage(), [
                'exception' => $e,
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Store notification in the database
     *
     * @param Notifiable $notifiable The entity receiving the notification
     * @param array $data Original notification data
     * @param array $formatted Formatted notification data
     * @return void
     */
    private function storeNotification(Notifiable $notifiable, array $data, array $formatted): void
    {
        try {
            // Check if database Connection and QueryBuilder exist
            if (
                !class_exists('\\Glueful\\Database\\Connection') ||
                !class_exists('\\Glueful\\Database\\QueryBuilder')
            ) {
                return;
            }

            // Create database connection and query builder instance
            $connection = new \Glueful\Database\Connection();
            $queryBuilder = new \Glueful\Database\QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Prepare notification data
            $notificationData = [
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'user_uuid' => $notifiable->getNotifiableId(),
                'type' => $data['type'] ?? 'unknown',
                'severity' => $data['severity'] ?? $this->config['default_level'],
                'title' => $formatted['title'] ?? '',
                'content' => $formatted['content'] ?? '',
                'data' => json_encode($data),
                'read' => false,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Insert notification in database using query builder
            $success = $queryBuilder->insert('security_notifications', $notificationData);

            if (!$success && $this->logger) {
                $this->logger->warning('Failed to store security notification in database');
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to store security notification: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Forward notification to other channels based on configuration
     *
     * @param Notifiable $notifiable The entity receiving the notification
     * @param array $data Original notification data
     * @param array $formatted Formatted notification data
     * @return void
     */
    private function forwardToChannels(Notifiable $notifiable, array $data, array $formatted): void
    {
        // Forward based on severity and configuration
        $severity = $data['severity'] ?? $this->config['default_level'];
        $forwardChannels = $this->config['forward_channels'] ?? [];

        if (empty($forwardChannels)) {
            return;
        }

        // Check if ChannelManager is available for forwarding
        if (!class_exists('\\Glueful\\Notifications\\Services\\ChannelManager')) {
            return;
        }

        try {
            $channelManager = new \Glueful\Notifications\Services\ChannelManager();

            foreach ($forwardChannels as $channelName) {
                // Skip if this channel should not receive this severity
                if (
                    isset($this->config['channel_severity_mapping'][$channelName]) &&
                    !in_array($severity, $this->config['channel_severity_mapping'][$channelName])
                ) {
                    continue;
                }

                // Create notification data for the target channel
                $channelData = [
                    'title' => $formatted['title'] ?? 'Security Alert',
                    'content' => $formatted['content'] ?? '',
                    'type' => $data['type'] ?? 'security_alert',
                    'severity' => $severity,
                    'source' => 'security_scanner',
                    'original_data' => $data
                ];

                // Send to the channel
                $channelManager->getChannel($channelName)->send($notifiable, $channelData);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to forward security notification: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Format the notification data for this channel
     *
     * @param array $data The raw notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @return array The formatted notification data
     */
    public function format(array $data, Notifiable $notifiable): array
    {
        // Use the formatter to format the notification
        return $this->formatter->format($data);
    }

    /**
     * Get the channel configuration
     *
     * @return array The channel configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
