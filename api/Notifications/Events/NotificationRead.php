<?php

declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationRead
 *
 * Event triggered when a notification is confirmed as read/viewed by the recipient.
 * Provides detailed tracking of user engagement with notifications.
 *
 * @package Glueful\Notifications\Events
 */
class NotificationRead extends NotificationEvent
{
    /**
     * @var DateTime When the notification was read
     */
    private DateTime $readAt;

    /**
     * @var array Optional metadata about the read event
     */
    private array $readMetadata;

    /**
     * NotificationRead constructor
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The channel where notification was read
     * @param array $readMetadata Additional metadata about the read event
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        string $channel,
        array $readMetadata = [],
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, $channel, $data);
        $this->readAt = new DateTime();
        $this->readMetadata = $readMetadata;
    }

    /**
     * Get the event name
     *
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.read';
    }

    /**
     * Get the read timestamp
     *
     * @return DateTime When the notification was read
     */
    public function getReadAt(): DateTime
    {
        return $this->readAt;
    }

    /**
     * Get the read metadata
     *
     * @return array Metadata about the read event
     */
    public function getReadMetadata(): array
    {
        return $this->readMetadata;
    }

    /**
     * Convert the event to an array
     *
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['read_at'] = $this->readAt->format('Y-m-d H:i:s');
        $data['read_metadata'] = $this->readMetadata;

        return $data;
    }
}
