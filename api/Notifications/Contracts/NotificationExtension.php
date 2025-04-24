<?php
declare(strict_types=1);

namespace Glueful\Notifications\Contracts;

/**
 * NotificationExtension Interface
 * 
 * Defines the contract for notification system extensions.
 * Extensions provide additional functionality to the core notification system.
 * 
 * @package Glueful\Notifications\Contracts
 */
interface NotificationExtension
{
    /**
     * Get the extension name.
     * 
     * @return string The name of the notification extension
     */
    public function getExtensionName(): string;
    
    /**
     * Initialize the extension.
     * 
     * @param array $config Configuration options for the extension
     * @return bool Whether the initialization was successful
     */
    public function initialize(array $config = []): bool;
    
    /**
     * Get the supported notification types.
     * 
     * @return array List of notification types supported by this extension
     */
    public function getSupportedNotificationTypes(): array;
    
    /**
     * Process the notification before it's sent.
     * 
     * @param array $data The notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @param string $channel The notification channel
     * @return array The processed notification data
     */
    public function beforeSend(array $data, Notifiable $notifiable, string $channel): array;
    
    /**
     * Process after a notification has been sent.
     * 
     * @param array $data The notification data
     * @param Notifiable $notifiable The entity that received the notification
     * @param string $channel The notification channel
     * @param bool $success Whether the notification was sent successfully
     * @return void
     */
    public function afterSend(array $data, Notifiable $notifiable, string $channel, bool $success): void;
}