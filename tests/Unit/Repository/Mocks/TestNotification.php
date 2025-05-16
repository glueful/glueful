<?php

namespace Tests\Unit\Repository\Mocks;

use Glueful\Notifications\Models\Notification;
use DateTime;

/**
 * Test Notification Class
 *
 * Extends the real Notification class but overrides the fromArray method
 * to handle test scenarios better.
 */
class TestNotification extends Notification
{
    /**
     * Override fromArray method to handle test data
     *
     * @param array $data Database record
     * @return Notification
     */
    public static function fromArray(array $data): Notification
    {
        // Ensure required fields are present with default values if missing
        $type = $data['type'] ?? 'test-type';
        $subject = $data['subject'] ?? 'Test Subject';
        $notifiableType = $data['notifiable_type'] ?? 'user';
        $notifiableId = $data['notifiable_id'] ?? 'user-id';

        $notification = new Notification(
            $type,
            $subject,
            $notifiableType,
            $notifiableId,
            isset($data['data']) ? (is_string($data['data']) ? json_decode($data['data'], true) : $data['data']) : null,
            $data['uuid'] ?? null,
            isset($data['id']) ? (string)$data['id'] : null
        );

        if (isset($data['priority'])) {
            $notification->setPriority($data['priority']);
        }

        if (!empty($data['read_at'])) {
            $readAt = new DateTime($data['read_at']);
            $notification->markAsRead($readAt);
        }

        if (!empty($data['scheduled_at'])) {
            $notification->schedule(new DateTime($data['scheduled_at']));
        }

        if (!empty($data['sent_at'])) {
            $sentAt = new DateTime($data['sent_at']);
            $notification->markAsSent($sentAt);
        }

        // Use reflection to set created_at and updated_at
        $reflectionClass = new \ReflectionClass($notification);

        if (!empty($data['created_at'])) {
            $createdAtProperty = $reflectionClass->getProperty('createdAt');
            $createdAtProperty->setAccessible(true);
            $createdAtProperty->setValue($notification, new DateTime($data['created_at']));
        }

        if (!empty($data['updated_at'])) {
            $updatedAtProperty = $reflectionClass->getProperty('updatedAt');
            $updatedAtProperty->setAccessible(true);
            $updatedAtProperty->setValue($notification, new DateTime($data['updated_at']));
        }

        return $notification;
    }
}
