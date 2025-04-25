<?php
declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationDelivered
 * 
 * Event triggered when a notification is confirmed as delivered to the recipient.
 * This is different from "sent" as it confirms the notification reached its destination.
 * 
 * @package Glueful\Notifications\Events
 */
class NotificationDelivered extends NotificationEvent
{
    /**
     * @var DateTime When the notification delivery was confirmed
     */
    private DateTime $deliveredAt;
    
    /**
     * @var array Delivery confirmation metadata
     */
    private array $deliveryMetadata;
    
    /**
     * NotificationDelivered constructor
     * 
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The delivery channel
     * @param array $deliveryMetadata Additional delivery confirmation data
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        string $channel,
        array $deliveryMetadata = [],
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, $channel, $data);
        $this->deliveredAt = new DateTime();
        $this->deliveryMetadata = $deliveryMetadata;
    }
    
    /**
     * Get the event name
     * 
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.delivered';
    }
    
    /**
     * Get the delivered timestamp
     * 
     * @return DateTime When the notification delivery was confirmed
     */
    public function getDeliveredAt(): DateTime
    {
        return $this->deliveredAt;
    }
    
    /**
     * Get the delivery metadata
     * 
     * @return array Delivery confirmation metadata
     */
    public function getDeliveryMetadata(): array
    {
        return $this->deliveryMetadata;
    }
    
    /**
     * Convert the event to an array
     * 
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['delivered_at'] = $this->deliveredAt->format('Y-m-d H:i:s');
        $data['delivery_metadata'] = $this->deliveryMetadata;
        
        return $data;
    }
}