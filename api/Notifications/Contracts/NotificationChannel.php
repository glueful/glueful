<?php
declare(strict_types=1);

namespace Glueful\Notifications\Contracts;

/**
 * NotificationChannel Interface
 * 
 * Defines the contract for notification delivery channels.
 * Each channel represents a way to deliver notifications (email, SMS, database, etc.).
 * 
 * @package Glueful\Notifications\Contracts
 */
interface NotificationChannel
{
    /**
     * Get the channel name.
     * 
     * @return string The name of the notification channel
     */
    public function getChannelName(): string;
    
    /**
     * Send the notification to the specified notifiable entity.
     * 
     * @param Notifiable $notifiable The entity receiving the notification
     * @param array $data Notification data including content and metadata
     * @return bool Whether the notification was sent successfully
     */
    public function send(Notifiable $notifiable, array $data): bool;
    
    /**
     * Format the notification data for this channel.
     * 
     * @param array $data The raw notification data
     * @param Notifiable $notifiable The entity receiving the notification
     * @return array The formatted notification data
     */
    public function format(array $data, Notifiable $notifiable): array;
    
    /**
     * Determine if the channel is available for sending notifications.
     * 
     * @return bool Whether the channel is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get channel-specific configuration.
     * 
     * @return array The channel configuration
     */
    public function getConfig(): array;
}