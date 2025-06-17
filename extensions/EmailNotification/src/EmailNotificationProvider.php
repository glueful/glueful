<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Services\ChannelManager;

/**
 * Email Notification Provider
 *
 * Registers the email notification channel with the notification system.
 *
 * @package Glueful\Extensions\EmailNotification
 */
class EmailNotificationProvider implements NotificationExtension
{
    /**
     * @var array Configuration settings
     */
    private array $config;
    /**
     * @var bool Whether the extension has been initialized
     */
    private bool $initialized = false;
    /**
     * @var EmailChannel|null The email channel instance
     */
    private ?EmailChannel $channel = null;

    /**
     * @var LogManager Logger instance
     */
    private LogManager $logger;

    /**
     * Provider constructor
     *
     * @param array $config Optional configuration to override defaults
     */
    public function __construct(array $config = [])
    {
        // Load core mail configuration from services.php
        $coreMailConfig = config('services.mail') ?? [];

        // Load extension-specific configuration
        $extensionConfig = require __DIR__ . '/config.php';

        // Transform core mail config to match EmailChannel expectations
        $transformedCoreConfig = $this->transformCoreMailConfig($coreMailConfig);

        // Merge configurations: transformed core config + extension features + provided overrides
        $this->config = array_merge($transformedCoreConfig, $extensionConfig, $config);

        // Initialize logger
        $this->logger = new LogManager('email_notification');
    }

    /**
     * Transform core mail config to match EmailChannel expectations
     *
     * @param array $coreConfig Core mail config from services.php
     * @return array Transformed config for EmailChannel
     */
    private function transformCoreMailConfig(array $coreConfig): array
    {
        if (empty($coreConfig)) {
            return [];
        }

        $transformed = [];

        // Get the default mailer type (smtp, ses, etc.)
        $defaultMailer = $coreConfig['default'] ?? 'smtp';

        // Transform SMTP config if available
        if (isset($coreConfig[$defaultMailer]) && $defaultMailer === 'smtp') {
            $smtpConfig = $coreConfig[$defaultMailer];

            // Map to EmailChannel expected structure
            $transformed = [
                'host' => $smtpConfig['host'] ?? '',
                'port' => $smtpConfig['port'] ?? 587,
                'username' => $smtpConfig['username'] ?? '',
                'password' => $smtpConfig['password'] ?? '',
                'encryption' => $smtpConfig['encryption'] ?? 'tls',
                'smtp_auth' => true,
            ];
        }

        // Copy from address configuration
        if (isset($coreConfig['from'])) {
            $transformed['from'] = $coreConfig['from'];
        }

        // Copy other relevant settings
        if (isset($coreConfig['retry'])) {
            $transformed['retry'] = $coreConfig['retry'];
        }

        return $transformed;
    }

    /**
     * Get the extension name
     *
     * @return string The name of the notification extension
     */
    public function getExtensionName(): string
    {
        return 'email_notification';
    }
    /**
     * Initialize the extension
     *
     * @param array $config Configuration options for the extension
     * @return bool Whether the initialization was successful
     */
    public function initialize(array $config = []): bool
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }

        try {
            // Create the formatter
            $formatter = new EmailFormatter();

            // Create the channel
            $this->channel = new EmailChannel($this->config, $formatter);

            // Check if the channel is available
            if (!$this->channel->isAvailable()) {
                return false;
            }

            $this->initialized = true;
            return true;
        } catch (\Exception $e) {
            // Log the error using LogManager
            $this->logger->error('Failed to initialize email notification extension: ' . $e->getMessage(), [
                'exception' => $e,
                'config' => $this->config
            ]);

            return false;
        }
    }

    /**
     * Get the supported notification types
     *
     * @return array List of notification types supported by this extension
     */
    public function getSupportedNotificationTypes(): array
    {
        // Email channel can handle all notification types
        return [
            '*', // Wildcard to indicate support for all types
            'welcome',
            'password_reset',
            'account_verification',
            'security_alert',
            'system_notification',
            'user_mention'
        ];
    }

    /**
     * Process the notification before it's sent
     *
     * @param array $data The notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @param string $channel The notification channel
     * @return array The processed notification data
     */
    public function beforeSend(array $data, Notifiable $notifiable, string $channel): array
    {
        // Only process if this is for the email channel
        if ($channel !== 'email') {
            return $data;
        }

        // Add app name to the data for template rendering if not present
        if (!isset($data['app_name'])) {
            $data['app_name'] = $this->config['app_name'] ?? 'Glueful Application';
        }

        // Add current year if not present (useful for copyright notices in templates)
        if (!isset($data['current_year'])) {
            $data['current_year'] = date('Y');
        }

        // Check debug mode
        if (!empty($this->config['debug'])) {
            // In debug mode, log the email but don't modify the data
            $this->logger->debug('Email notification to be sent', [
                'recipient' => $notifiable->routeNotificationFor('email'),
                'subject' => $data['subject'] ?? '',
                'notification_type' => $data['type'] ?? 'unknown',
            ]);
        }

        return $data;
    }

    /**
     * Process after a notification has been sent
     *
     * @param array $data The notification data
     * @param Notifiable $notifiable The entity that received the notification
     * @param string $channel The notification channel
     * @param bool $success Whether the notification was sent successfully
     * @return void
     */
    public function afterSend(array $data, Notifiable $notifiable, string $channel, bool $success): void
    {
        // Only process if this is for the email channel
        if ($channel !== 'email') {
            return;
        }

        // Log the result if logging is enabled
        if (!empty($this->config['logging']['enabled'])) {
            if ($success) {
                $this->logger->info('Email notification sent successfully', [
                    'recipient' => $notifiable->routeNotificationFor('email'),
                    'subject' => $data['subject'] ?? '',
                    'notification_type' => $data['type'] ?? 'unknown'
                ]);
            } else {
                $this->logger->error('Failed to send email notification', [
                    'recipient' => $notifiable->routeNotificationFor('email'),
                    'subject' => $data['subject'] ?? '',
                    'notification_type' => $data['type'] ?? 'unknown'
                ]);
            }
        }
    }

    /**
     * Register the notification channel with the channel manager
     *
     * @param ChannelManager $channelManager The notification channel manager
     * @return void
     */
    public function register(ChannelManager $channelManager): void
    {
        // Initialize the extension if not already initialized
        if (!$this->initialized) {
            $this->initialize($this->config);
        }

        // Register the channel with the channel manager
        if ($this->channel !== null) {
            $channelManager->registerChannel($this->channel);
        }
    }

    /**
     * Get extension information
     *
     * @return array Extension metadata
     */
    public function getExtensionInfo(): array
    {
        return [
            'name' => 'Email Notification Channel',
            'version' => '1.0.0',
            'description' => 'Provides email notification capabilities using SMTP/PHPMailer',
            'author' => 'Glueful',
            'channels' => ['email'],
            'config' => $this->config
        ];
    }

    /**
     * Check if email provider is properly configured
     *
     * @return bool True if email provider is properly configured
     */
    public function isEmailProviderConfigured(): bool
    {
        try {
            // Check if email notification extension is enabled
            $extensionsConfig = config('extensions');
            if (
                empty($extensionsConfig) ||
                !is_array($extensionsConfig) ||
                !isset($extensionsConfig['enabled']) ||
                !is_array($extensionsConfig['enabled']) ||
                !in_array('EmailNotification', $extensionsConfig['enabled'])
            ) {
                $this->logger->error("EmailNotification extension is not enabled");
                return false;
            }

            // Check mail configuration
            $mailConfig = config('services.mail');
            if (empty($mailConfig) || !is_array($mailConfig)) {
                $this->logger->error("Mail configuration is missing or invalid");
                return false;
            }

            // Get the default mailer (smtp, ses, mailgun, etc.)
            $defaultMailer = $mailConfig['default'] ?? 'smtp';
            // Check if the mailer configuration exists
            if (!isset($mailConfig[$defaultMailer])) {
                $this->logger->error("Mail driver '{$defaultMailer}' configuration is missing");
                return false;
            }

            $driverConfig = $mailConfig[$defaultMailer];

            // Check specific driver requirements
            switch ($defaultMailer) {
                case 'smtp':
                    if (empty($driverConfig['host'])) {
                        $this->logger->error("SMTP host is not configured");
                        return false;
                    }
                    if (empty($driverConfig['port'])) {
                        $this->logger->error("SMTP port is not configured");
                        return false;
                    }
                    break;

                case 'ses':
                    if (empty($driverConfig['key']) || empty($driverConfig['secret'])) {
                        $this->logger->error("Amazon SES credentials are missing");
                        return false;
                    }
                    break;

                case 'mailgun':
                    if (empty($driverConfig['domain']) || empty($driverConfig['secret'])) {
                        $this->logger->error("Mailgun credentials are missing");
                        return false;
                    }
                    break;
            }

            // Check from address is configured
            if (empty($mailConfig['from']['address'])) {
                $this->logger->error("From email address is not configured");
                return false;
            }

            // Check if the channel is initialized and available
            if (!$this->initialized || $this->channel === null) {
                $this->logger->error("Email provider is not initialized");
                return false;
            }

            return $this->channel->isAvailable();
        } catch (\Exception $e) {
            $this->logger->error("Error checking email provider configuration: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the configuration
     *
     * @return array Configuration settings
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

    /**
     * Get resource metrics for this provider
     *
     * Returns information about email sending metrics like:
     * - Emails sent count
     * - Success rate
     * - Average delivery time
     *
     * @return array Email provider metrics
     */
    public function getMetrics(): array
    {
        // Default metrics
        $metrics = [
            'emails_sent' => 0,
            'emails_failed' => 0,
            'success_rate' => 100,
            'avg_delivery_time' => 0,
            'last_email_sent' => null,
            'email_queue_size' => 0,
            'most_common_types' => [],
            'read_rate' => 0
        ];

        try {
            // Use QueryBuilder instead of direct SQL
            $connection = new \Glueful\Database\Connection();
            $queryBuilder = new \Glueful\Database\QueryBuilder(
                $connection->getPDO(),
                $connection->getDriver(),
                new \Glueful\Database\QueryLogger()
            );

            // Count total emails sent through email channel
            $sentEmails = $queryBuilder
                ->select('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('sent_at')
                ->count('notifications');

            $metrics['emails_sent'] = $sentEmails;

            // Count failed emails (those with error data)
            $failedEmails = $queryBuilder
                ->select('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereJsonContains('data', 'true', '$.error')
                ->count('notifications');

            $metrics['emails_failed'] = $failedEmails;

            // Calculate success rate if we have data
            if (($metrics['emails_sent'] + $metrics['emails_failed']) > 0) {
                $metrics['success_rate'] = round(
                    ($metrics['emails_sent'] / ($metrics['emails_sent'] + $metrics['emails_failed'])) * 100,
                    2
                );
            }

            // Get last sent email timestamp
            $lastSentEmail = $queryBuilder
                ->select('notifications', ['sent_at'])
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('sent_at')
                ->orderBy(['sent_at' => 'DESC'])
                ->limit(1)
                ->first();

            $metrics['last_email_sent'] = $lastSentEmail ? $lastSentEmail['sent_at'] : null;

            // Calculate read rate
            $readEmails = $queryBuilder
                ->select('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('read_at')
                ->count('notifications');

            if ($metrics['emails_sent'] > 0) {
                $metrics['read_rate'] = round(($readEmails / $metrics['emails_sent']) * 100, 2);
            }

            // Get most common notification types for emails
            // We'll use a raw query for this aggregation as it's more complex
            $commonTypes = $queryBuilder->rawQuery("
                SELECT type, COUNT(*) as count 
                FROM notifications 
                WHERE JSON_CONTAINS(data, '\"email\"', '$.channels')
                GROUP BY type
                ORDER BY count DESC
                LIMIT 5
            ");

            $metrics['most_common_types'] = array_column($commonTypes, 'count', 'type');

            // Get email queue size - scheduled emails not yet sent
            $queueSize = $queryBuilder
                ->select('notifications')
                ->whereJsonContains('data', 'email', '$.channels')
                ->whereNotNull('scheduled_at')
                ->whereNull('sent_at')
                ->count('notifications');

            $metrics['email_queue_size'] = $queueSize;
        } catch (\Exception $e) {
            $this->logger->error("Error getting email metrics from database: " . $e->getMessage());
            // Return default metrics on error
        }

        return $metrics;
    }
}
