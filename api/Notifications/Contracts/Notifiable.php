<?php
declare(strict_types=1);

namespace Glueful\Notifications\Contracts;

/**
 * Notifiable Interface
 * 
 * Defines the contract for entities that can receive notifications.
 * Any class implementing this interface can be a recipient of notifications.
 * 
 * @package Glueful\Notifications\Contracts
 */
interface Notifiable
{
    /**
     * Get the notification routing information for the given channel.
     * 
     * @param string $channel The notification channel
     * @return mixed The address to send notifications to on the given channel
     */
    public function routeNotificationFor(string $channel);
    
    /**
     * Get the unique identifier for the notifiable entity.
     * 
     * @return string The identifier of the notifiable entity
     */
    public function getNotifiableId(): string;
    
    /**
     * Get the notifiable entity type.
     * 
     * @return string The class name or type of the notifiable entity
     */
    public function getNotifiableType(): string;
    
    /**
     * Determine if the notifiable entity should receive the notification.
     * 
     * @param string $notificationType The type of notification
     * @param string $channel The notification channel
     * @return bool Whether the notification should be sent
     */
    public function shouldReceiveNotification(string $notificationType, string $channel): bool;
    
    /**
     * Get notification preferences for this notifiable entity.
     * 
     * @return array The notification preferences
     */
    public function getNotificationPreferences(): array;
}