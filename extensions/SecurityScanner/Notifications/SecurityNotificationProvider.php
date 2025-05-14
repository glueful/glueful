<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Notifications;

use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Services\ChannelManager;

/**
 * Security Notification Provider
 *
 * Registers the security notification channel with the notification system.
 * Handles security alerts, vulnerability notifications, and scan reports.
 *
 * @package Glueful\Extensions\SecurityScanner\Notifications
 */
class SecurityNotificationProvider implements NotificationExtension
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
     * @var SecurityChannel|null The security channel instance
     */
    private ?SecurityChannel $channel = null;

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
        // Load default config
        $defaultConfig = [
            'notification_levels' => ['critical', 'high', 'medium', 'low'],
            'default_level' => 'medium',
            'notification_categories' => [
                'vulnerability_detected',
                'scan_completed',
                'remediation_required',
                'security_alert'
            ],
            'logging' => [
                'enabled' => true,
                'level' => 'info'
            ]
        ];

        // Merge with provided config
        $this->config = array_merge($defaultConfig, $config);

        // Initialize logger
        $this->logger = new LogManager('security_notification');
    }

    /**
     * Get the extension name
     *
     * @return string The name of the notification extension
     */
    public function getExtensionName(): string
    {
        return 'security_notification';
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
            $formatter = new SecurityNotificationFormatter();

            // Create the channel
            $this->channel = new SecurityChannel($this->config, $formatter);

            // Check if the channel is available
            if (!$this->channel->isAvailable()) {
                return false;
            }

            $this->initialized = true;
            return true;
        } catch (\Exception $e) {
            // Log the error using LogManager
            $this->logger->error('Failed to initialize security notification extension: ' . $e->getMessage(), [
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
        return [
            'vulnerability_detected',
            'scan_completed',
            'remediation_required',
            'security_alert',
            'dependency_vulnerability',
            'code_vulnerability',
            'api_vulnerability'
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
        // Only process if this is for the security channel
        if ($channel !== 'security') {
            return $data;
        }

        // Add severity level if not present
        if (!isset($data['severity'])) {
            $data['severity'] = $this->config['default_level'];
        }

        // Add timestamp if not present
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = time();
        }

        // Format vulnerability data if present
        if (isset($data['vulnerability']) && !isset($data['vulnerability_formatted'])) {
            $data['vulnerability_formatted'] = $this->formatVulnerability($data['vulnerability']);
        }

        // Check debug mode
        if (!empty($this->config['debug'])) {
            // In debug mode, log the notification but don't modify the data
            $this->logger->debug('Security notification to be sent', [
                'recipient' => $notifiable->routeNotificationFor('security'),
                'type' => $data['type'] ?? 'unknown',
                'severity' => $data['severity'] ?? 'medium',
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
        // Only process if this is for the security channel
        if ($channel !== 'security') {
            return;
        }

        // Log the result if logging is enabled
        if (!empty($this->config['logging']['enabled'])) {
            if ($success) {
                $this->logger->info('Security notification sent successfully', [
                    'recipient' => $notifiable->routeNotificationFor('security'),
                    'type' => $data['type'] ?? 'unknown',
                    'severity' => $data['severity'] ?? 'medium'
                ]);
            } else {
                $this->logger->error('Failed to send security notification', [
                    'recipient' => $notifiable->routeNotificationFor('security'),
                    'type' => $data['type'] ?? 'unknown',
                    'severity' => $data['severity'] ?? 'medium'
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

        // Check if initialized successfully
        if (!$this->initialized || !$this->channel) {
            $this->logger->error('Cannot register security notification channel - not initialized');
            return;
        }

        try {
            // Register the channel with the channel manager
            $channelManager->registerChannel($this->channel);

            $this->logger->info('Security notification channel registered successfully');
        } catch (\Exception $e) {
            $this->logger->error('Failed to register security notification channel: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Check if the email provider is configured correctly
     *
     * @return bool Whether the provider is configured correctly
     */
    public function isSecurityProviderConfigured(): bool
    {
        return $this->initialized && $this->channel !== null;
    }

    /**
     * Format vulnerability data for display
     *
     * @param array $vulnerability The vulnerability data
     * @return string Formatted vulnerability information
     */
    private function formatVulnerability(array $vulnerability): string
    {
        $output = '';

        if (isset($vulnerability['title'])) {
            $output .= "Title: " . $vulnerability['title'] . "\n";
        }

        if (isset($vulnerability['severity'])) {
            $output .= "Severity: " . strtoupper($vulnerability['severity']) . "\n";
        }

        if (isset($vulnerability['description'])) {
            $output .= "Description: " . $vulnerability['description'] . "\n";
        }

        if (isset($vulnerability['location'])) {
            $output .= "Location: " . $vulnerability['location'] . "\n";
        }

        if (isset($vulnerability['recommendation'])) {
            $output .= "Recommendation: " . $vulnerability['recommendation'] . "\n";
        }

        return $output;
    }
}
