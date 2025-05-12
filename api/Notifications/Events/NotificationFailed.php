<?php

declare(strict_types=1);

namespace Glueful\Notifications\Events;

use DateTime;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Models\Notification;
use Throwable;

/**
 * NotificationFailed
 *
 * Event triggered when a notification fails to be sent.
 * Contains information about the failure reason and any associated exception.
 *
 * @package Glueful\Notifications\Events
 */
class NotificationFailed extends NotificationEvent
{
    /**
     * @var string Reason for the failure
     */
    private string $reason;

    /**
     * @var Throwable|null The exception that caused the failure, if any
     */
    private ?Throwable $exception;

    /**
     * @var DateTime When the failure occurred
     */
    private DateTime $failedAt;

    /**
     * NotificationFailed constructor
     *
     * @param Notification $notification The notification
     * @param Notifiable $notifiable The recipient
     * @param string $channel The delivery channel
     * @param string $reason The failure reason
     * @param Throwable|null $exception The exception that caused the failure
     * @param array $data Additional event data
     */
    public function __construct(
        Notification $notification,
        Notifiable $notifiable,
        string $channel,
        string $reason,
        ?Throwable $exception = null,
        array $data = []
    ) {
        parent::__construct($notification, $notifiable, $channel, $data);
        $this->reason = $reason;
        $this->exception = $exception;
        $this->failedAt = new DateTime();
    }

    /**
     * Get the event name
     *
     * @return string Event name
     */
    public function getName(): string
    {
        return 'notification.failed';
    }

    /**
     * Get the failure reason
     *
     * @return string Failure reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get the exception that caused the failure
     *
     * @return Throwable|null The exception
     */
    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    /**
     * Get the exception message
     *
     * @return string|null Exception message
     */
    public function getExceptionMessage(): ?string
    {
        return $this->exception ? $this->exception->getMessage() : null;
    }

    /**
     * Get the failed timestamp
     *
     * @return DateTime When the failure occurred
     */
    public function getFailedAt(): DateTime
    {
        return $this->failedAt;
    }

    /**
     * Convert the event to an array
     *
     * @return array Event as array
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['reason'] = $this->reason;
        $data['failed_at'] = $this->failedAt->format('Y-m-d H:i:s');

        if ($this->exception) {
            $data['exception'] = [
                'message' => $this->exception->getMessage(),
                'code' => $this->exception->getCode(),
                'class' => get_class($this->exception),
                'file' => $this->exception->getFile(),
                'line' => $this->exception->getLine()
            ];
        }

        return $data;
    }
}
