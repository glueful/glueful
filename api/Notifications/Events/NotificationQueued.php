<?php
declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationQueued
 * 
 * Event triggered when a notification is queued for later processing.
 * 
 * @package Glueful\Notifications\Events
 */
class NotificationQueued extends NotificationEvent
{
    /**
     * @var DateTime When the notification was queued
     */
    private DateTime $queuedAt;
    
    /**
     * NotificationQueued constructor
     * 
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, null, $data);
        $this->queuedAt = new DateTime();
    }
    
    /**
     * Get the event name
     * 
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.queued';
    }
    
    /**
     * Get the queued timestamp
     * 
     * @return DateTime When the notification was queued
     */
    public function getQueuedAt(): DateTime
    {
        return $this->queuedAt;
    }
    
    /**
     * Convert the event to an array
     * 
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['queued_at'] = $this->queuedAt->format('Y-m-d H:i:s');
        
        return $data;
    }
}