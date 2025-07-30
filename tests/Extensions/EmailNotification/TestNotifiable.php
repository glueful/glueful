<?php

declare(strict_types=1);

namespace Tests\Extensions\EmailNotification;

use Glueful\Notifications\Contracts\Notifiable;

/**
 * Test implementation of Notifiable
 */
class TestNotifiable implements Notifiable
{
    private string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }

    public function getNotifiableId(): string
    {
        return 'test-user-123';
    }

    public function getNotifiableType(): string
    {
        return 'user';
    }

    public function routeNotificationFor(string $channel)
    {
        if ($channel === 'email') {
            return $this->email;
        }
        return null;
    }

    public function shouldReceiveNotification(string $notificationType, string $channel): bool
    {
        return true; // Test user receives all notifications
    }

    public function getNotificationPreferences(): array
    {
        return [
            'channels' => ['email'],
            'frequency' => 'immediate'
        ];
    }
}
