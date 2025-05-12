<?php

declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationScheduled
 *
 * Event triggered when a notification is scheduled for future delivery.
 *
 * @package Glueful\Notifications\Events
 */
class NotificationScheduled extends NotificationEvent
{
    /**
     * @var DateTime When the notification was scheduled
     */
    private DateTime $scheduledAt;

    /**
     * NotificationScheduled constructor
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param DateTime $scheduledAt When the notification is scheduled to be sent
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        DateTime $scheduledAt,
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, null, $data);
        $this->scheduledAt = $scheduledAt;
    }

    /**
     * Get the event name
     *
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.scheduled';
    }

    /**
     * Get the scheduled timestamp
     *
     * @return DateTime When the notification is scheduled to be sent
     */
    public function getScheduledAt(): DateTime
    {
        return $this->scheduledAt;
    }

    /**
     * Convert the event to an array
     *
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['scheduled_at'] = $this->scheduledAt->format('Y-m-d H:i:s');

        return $data;
    }
}
