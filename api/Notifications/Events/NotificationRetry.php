<?php

declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;

/**
 * NotificationRetry
 *
 * Event triggered when a notification is being retried after previous failure.
 * Contains details about the retry attempt.
 *
 * @package Glueful\Notifications\Events
 */
class NotificationRetry extends NotificationEvent
{
    /**
     * @var DateTime When the notification retry occurred
     */
    private DateTime $retryAt;

    /**
     * @var int The current retry attempt number
     */
    private int $attemptNumber;

    /**
     * @var string|null The reason for the previous failure
     */
    private ?string $previousFailureReason;

    /**
     * NotificationRetry constructor
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The delivery channel
     * @param int $attemptNumber The current retry attempt number
     * @param string|null $previousFailureReason Reason for the previous failure
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        string $channel,
        int $attemptNumber,
        ?string $previousFailureReason = null,
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, $channel, $data);
        $this->retryAt = new DateTime();
        $this->attemptNumber = $attemptNumber;
        $this->previousFailureReason = $previousFailureReason;
    }

    /**
     * Get the event name
     *
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.retry';
    }

    /**
     * Get the retry timestamp
     *
     * @return DateTime When the notification retry occurred
     */
    public function getRetryAt(): DateTime
    {
        return $this->retryAt;
    }

    /**
     * Get the retry attempt number
     *
     * @return int The current retry attempt number
     */
    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    /**
     * Get the previous failure reason
     *
     * @return string|null The reason for the previous failure
     */
    public function getPreviousFailureReason(): ?string
    {
        return $this->previousFailureReason;
    }

    /**
     * Convert the event to an array
     *
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['retry_at'] = $this->retryAt->format('Y-m-d H:i:s');
        $data['attempt_number'] = $this->attemptNumber;

        if ($this->previousFailureReason) {
            $data['previous_failure_reason'] = $this->previousFailureReason;
        }

        return $data;
    }
}
