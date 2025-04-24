<?php
declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationEvent
 * 
 * Base class for all notification-related events.
 * Provides common properties and methods used across notification events.
 * 
 * @package Glueful\Notifications\Events
 */
abstract class NotificationEvent
{
    /**
     * @var Notification The notification that triggered the event
     */
    protected Notification $notification;
    
    /**
     * @var Notifiable The recipient of the notification
     */
    protected Notifiable $notifiable;
    
    /**
     * @var string|null The channel used for notification delivery
     */
    protected ?string $channel;
    
    /**
     * @var DateTime Timestamp when the event occurred
     */
    protected DateTime $timestamp;
    
    /**
     * @var array Additional event data
     */
    protected array $data;
    
    /**
     * NotificationEvent constructor
     * 
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string|null $channel The delivery channel
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        ?string $channel = null,
        array $data = []
    ) {
        $this->notification = $notification;
        $this->notifiable = $notifiable;
        $this->channel = $channel;
        $this->timestamp = new DateTime();
        $this->data = $data;
    }
    
    /**
     * Get the notification
     * 
     * @return Notification The notification
     */
    public function getNotification(): Notification
    {
        return $this->notification;
    }
    
    /**
     * Get the notifiable
     * 
     * @return Notifiable The recipient
     */
    public function getNotifiable(): Notifiable
    {
        return $this->notifiable;
    }
    
    /**
     * Get the channel
     * 
     * @return string|null The channel
     */
    public function getChannel(): ?string
    {
        return $this->channel;
    }
    
    /**
     * Get the timestamp
     * 
     * @return DateTime Event timestamp
     */
    public function getTimestamp(): DateTime
    {
        return $this->timestamp;
    }
    
    /**
     * Get additional data
     * 
     * @return array Event data
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * Get a specific data value
     * 
     * @param string $key Data key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Data value
     */
    public function getDataValue(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }
    
    /**
     * Get the event name
     * 
     * @return string Event name
     */
    abstract public function getName(): string;
    
    /**
     * Convert the event to an array
     * 
     * @return array Event as array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'notification_id' => $this->notification->getId(),
            'notification_type' => $this->notification->getType(),
            'notifiable_type' => $this->notifiable->getNotifiableType(),
            'notifiable_id' => $this->notifiable->getNotifiableId(),
            'channel' => $this->channel,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'data' => $this->data
        ];
    }
}