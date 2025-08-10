<?php

declare(strict_types=1);

namespace Glueful\Extensions\EmailNotification;

/**
 * Dummy Notifiable for cases where we don't have a real notifiable
 */
class DummyNotifiable implements \Glueful\Notifications\Contracts\Notifiable
{
    public function getNotifiableId(): string
    {
        return 'system';
    }

    public function getNotifiableType(): string
    {
        return 'system';
    }

    public function routeNotificationFor(string $channel)
    {
        return null;
    }

    public function shouldReceiveNotification(string $notificationType, string $channel): bool
    {
        return true;
    }

    public function getNotificationPreferences(): array
    {
        return [];
    }
}
