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
        // Load default config
        $defaultConfig = require __DIR__ . '/config.php';
        
        // Merge with provided config
        $this->config = array_merge($defaultConfig, $config);
        
        // Initialize logger
        $this->logger = new LogManager('email_notification');
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
}