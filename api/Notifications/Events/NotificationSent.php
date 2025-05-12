<?php

declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationSent
 *
 * Event triggered when a notification is successfully sent.
 * Contains details about the notification delivery.
 *
 * @package Glueful\Notifications\Events
 */
class NotificationSent extends NotificationEvent
{
    /**
     * @var DateTime When the notification was sent
     */
    private DateTime $sentAt;

    /**
     * NotificationSent constructor
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The delivery channel
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        string $channel,
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, $channel, $data);
        $this->sentAt = new DateTime();
    }

    /**
     * Get the event name
     *
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.sent';
    }

    /**
     * Get the sent timestamp
     *
     * @return DateTime When the notification was sent
     */
    public function getSentAt(): DateTime
    {
        return $this->sentAt;
    }

    /**
     * Convert the event to an array
     *
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['sent_at'] = $this->sentAt->format('Y-m-d H:i:s');

        return $data;
    }
}
