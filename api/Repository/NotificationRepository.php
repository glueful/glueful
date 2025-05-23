<?php

declare(strict_types=1);

namespace Glueful\Repository;

use DateTime;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Models\NotificationTemplate;
use Glueful\Helpers\Utils;

/**
 * Notification Repository
 *
 * Handles all database operations related to notifications:
 * - Persisting notifications to database
 * - Retrieving notifications by various criteria
 * - Managing notification preferences
 * - Storing and retrieving notification templates
 *
 * Extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality for notification-related activities.
 *
 * @package Glueful\Repository
 */
class NotificationRepository extends BaseRepository
{
    /**
     * Initialize repository
     *
     * Sets up database connection and dependencies
     */
    public function __construct()
    {
        // Set the table and other configuration before calling parent constructor
        $this->table = 'notifications';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['*'];
        $this->containsSensitiveData = false;

        // Call parent constructor to set up database connection and audit logger
        parent::__construct();
    }

    /**
     * Save a notification to the database
     *
     * Creates or updates a notification record.
     *
     * @param Notification $notification The notification to save
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function save(Notification $notification, ?string $userId = null): bool
    {
        $data = $notification->toArray();

        // Convert data field to JSON
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }

        // Ensure UUID is present for new notifications
        if (!isset($data['uuid']) || empty($data['uuid'])) {
            $data['uuid'] = Utils::generateNanoID();
        }

        // Check if notification exists by UUID
        $existing = null;
        if (!empty($data['uuid'])) {
            $existing = $this->findByUuid($data['uuid']);
        }

        if ($existing) {
            // Update existing notification using BaseRepository's update method
            // This automatically handles audit logging
            $data['id'] = $existing->getId();
            return $this->update($data['uuid'], $data, $userId);
        } else {
            // Remove the ID field if it's NULL to let the database auto-increment
            if (isset($data['id']) && $data['id'] == null) {
                unset($data['id']);
            }

            // Create new notification using BaseRepository's create method
            $result = $this->create($data, $userId);
            return $result ? true : false;
        }
    }

    /**
     * Find notification by UUID
     *
     * This is the preferred method for looking up notifications
     * as it aligns with the UUID-based identifier pattern used across the system.
     *
     * @param string $uuid Notification UUID
     * @return Notification|null The notification or null if not found
     */
    public function findByUuid(string $uuid): ?Notification
    {
        // Use BaseRepository's findBy method for consistent behavior
        $result = $this->findBy($this->primaryKey, $uuid);

        if (!$result) {
            return null;
        }

        return Notification::fromArray($result);
    }

    /**
     * Find notifications for a specific recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param bool|null $onlyUnread Whether to get only unread notifications
     * @param int|null $limit Maximum number of notifications to retrieve
     * @param int|null $offset Pagination offset
     * @param array $filters Optional additional filters (type, priority, date range)
     * @return array Array of Notification objects
     */
    public function findForNotifiable(
        string $notifiableType,
        string $notifiableId,
        ?bool $onlyUnread = false,
        ?int $limit = null,
        ?int $offset = null,
        array $filters = []
    ): array {
        $query = $this->db->select($this->table, ['*'])
            ->where([
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId
            ]);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        // Apply additional filters if provided
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // Handle operators like 'gte', 'lte', etc.
                foreach ($value as $operator => $val) {
                    switch ($operator) {
                        case 'gte':
                            $query->whereRaw("{$field} >= ?", [$val]);
                            break;
                        case 'lte':
                            $query->whereRaw("{$field} <= ?", [$val]);
                            break;
                        case 'gt':
                            $query->whereRaw("{$field} > ?", [$val]);
                            break;
                        case 'lt':
                            $query->whereRaw("{$field} < ?", [$val]);
                            break;
                        case 'like':
                            $query->whereRaw("{$field} LIKE ?", ["%{$val}%"]);
                            break;
                        case 'in':
                            if (is_array($val) && !empty($val)) {
                                $placeholders = implode(',', array_fill(0, count($val), '?'));
                                $query->whereRaw("{$field} IN ({$placeholders})", $val);
                            }
                            break;
                    }
                }
            } else {
                $query->where([$field => $value]);
            }
        }

        // Order by creation date, newest first
        $query->orderBy(['created_at' => 'DESC']);

        if ($limit !== null) {
            $query->limit($limit);

            if ($offset !== null) {
                $query->offset($offset);
            }
        }

        $results = $query->get();

        if (!$results) {
            return [];
        }

        $notifications = [];
        foreach ($results as $row) {
            $notifications[] = Notification::fromArray($row);
        }

        return $notifications;
    }

    /**
     * Find pending scheduled notifications ready to be sent
     *
     * @param DateTime|null $now Current time (defaults to now)
     * @param int|null $limit Maximum number to retrieve
     * @return array Array of Notification objects
     */
    public function findPendingScheduled(?DateTime $now = null, ?int $limit = null): array
    {
        $now = $now ?? new DateTime();
        $currentTime = $now->format('Y-m-d H:i:s');

        $query = $this->db->select($this->table, ['*'])
            ->whereNotNull('scheduled_at')
            ->whereNull('sent_at')
            ->whereRaw("scheduled_at <= ?", [$currentTime]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $results = $query->get();

        if (!$results) {
            return [];
        }

        $notifications = [];
        foreach ($results as $row) {
            $notifications[] = Notification::fromArray($row);
        }

        return $notifications;
    }

    /**
     * Save a notification preference to the database
     *
     * @param NotificationPreference $preference The preference to save
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function savePreference(NotificationPreference $preference, ?string $userId = null): bool
    {
        // Store original table and primary key
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change table and primary key
            $this->table = 'notification_preferences';
            $this->primaryKey = 'uuid';

            $data = [
                'id' => $preference->getId(),
                'uuid' => $preference->getUuid() ?? Utils::generateNanoID(),
                'notifiable_type' => $preference->getNotifiableType(),
                'notifiable_id' => $preference->getNotifiableId(),
                'notification_type' => $preference->getNotificationType(),
                'channels' => json_encode($preference->getChannels()),
                'enabled' => $preference->isEnabled() ? 1 : 0,
                'settings' => json_encode($preference->getSettings())
            ];

            // Check if preference exists by UUID
            $existing = null;
            if (!empty($preference->getUuid())) {
                $existing = $this->findPreferenceByUuid($preference->getUuid());
            }

            if ($existing) {
                // Update existing preference
                return $this->update($data['uuid'], $data, $userId);
            } else {
                // Create new preference
                $result = $this->create($data, $userId);
                return $result ? true : false;
            }
        } finally {
            // Restore original table and primary key
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Find notification preference by UUID
     *
     * @param string $uuid Preference UUID
     * @return NotificationPreference|null The preference or null if not found
     */
    public function findPreferenceByUuid(string $uuid): ?NotificationPreference
    {
        // Store original table and primary key
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change table
            $this->table = 'notification_preferences';

            // Use BaseRepository's findBy method
            $data = $this->findBy('uuid', $uuid);

            if (!$data) {
                return null;
            }

            $channels = json_decode($data['channels'], true);
            $settings = json_decode($data['settings'], true);

            return new NotificationPreference(
                $data['id'],
                $data['notifiable_type'],
                $data['notifiable_id'],
                $data['notification_type'],
                $channels,
                (bool)$data['enabled'],
                $settings,
                $data['uuid'] ?? null
            );
        } finally {
            // Restore original table and primary key
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Find preferences for a specific recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @return array Array of NotificationPreference objects
     */
    public function findPreferencesForNotifiable(string $notifiableType, string $notifiableId): array
    {
        // Store original table and primary key
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change table
            $this->table = 'notification_preferences';

            $results = $this->db->select($this->table, ['*'])
                ->where([
                    'notifiable_type' => $notifiableType,
                    'notifiable_id' => $notifiableId
                ])
                ->get();

            if (!$results) {
                return [];
            }

            $preferences = [];
            foreach ($results as $row) {
                $channels = json_decode($row['channels'], true);
                $settings = json_decode($row['settings'], true);

                $preferences[] = new NotificationPreference(
                    $row['id'],
                    $row['notifiable_type'],
                    $row['notifiable_id'],
                    $row['notification_type'],
                    $channels,
                    (bool)$row['enabled'],
                    $settings,
                    $row['uuid'] ?? null
                );
            }

            return $preferences;
        } finally {
            // Restore original table and primary key
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Save a notification template to the database
     *
     * @param NotificationTemplate $template The template to save
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function saveTemplate(NotificationTemplate $template, ?string $userId = null): bool
    {
        // Store original table and primary key
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change table and primary key
            $this->table = 'notification_templates';
            $this->primaryKey = 'uuid';

            $data = [
                'id' => $template->getId(),
                'uuid' => $template->getUuid() ?? Utils::generateNanoID(),
                'name' => $template->getName(),
                'notification_type' => $template->getNotificationType(),
                'channel' => $template->getChannel(),
                'content' => $template->getContent(),
                'parameters' => json_encode($template->getParameters())
            ];

            // Check if template exists by UUID
            $existing = null;
            if (!empty($template->getUuid())) {
                $existing = $this->findTemplateByUuid($template->getUuid());
            }

            if ($existing) {
                // Update existing template
                return $this->update($data['uuid'], $data, $userId);
            } else {
                // Create new template
                $result = $this->create($data, $userId);
                return $result ? true : false;
            }
        } finally {
            // Restore original table and primary key
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Find notification template by UUID
     *
     * @param string $uuid Template UUID
     * @return NotificationTemplate|null The template or null if not found
     */
    public function findTemplateByUuid(string $uuid): ?NotificationTemplate
    {
        // Store original table and primary key
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change table
            $this->table = 'notification_templates';

            // Use BaseRepository's findBy method
            $data = $this->findBy('uuid', $uuid);

            if (!$data) {
                return null;
            }

            $parameters = json_decode($data['parameters'], true) ?? [];

            return new NotificationTemplate(
                $data['id'],
                $data['name'],
                $data['notification_type'],
                $data['channel'],
                $data['content'],
                $parameters,
                $data['uuid'] ?? null
            );
        } finally {
            // Restore original table and primary key
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Find templates for a notification type and channel
     *
     * @param string $notificationType Notification type
     * @param string $channel Channel name
     * @return array Array of NotificationTemplate objects
     */
    public function findTemplates(string $notificationType, string $channel): array
    {
        // Store original table and primary key
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change table
            $this->table = 'notification_templates';

            $results = $this->db->select($this->table, ['*'])
                ->where([
                    'notification_type' => $notificationType,
                    'channel' => $channel
                ])
                ->get();

            if (!$results) {
                return [];
            }

            $templates = [];
            foreach ($results as $row) {
                $parameters = json_decode($row['parameters'], true) ?? [];

                $templates[] = new NotificationTemplate(
                    $row['id'],
                    $row['name'],
                    $row['notification_type'],
                    $row['channel'],
                    $row['content'],
                    $parameters,
                    $row['uuid'] ?? null
                );
            }

            return $templates;
        } finally {
            // Restore original table and primary key
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Count all notifications for a recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param bool $onlyUnread Whether to count only unread notifications
     * @param array $filters Optional additional filters
     * @return int Total count of notifications
     */
    public function countForNotifiable(
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false,
        array $filters = []
    ): int {
        $query = $this->db->select($this->table, ['COUNT(*) as count'])
            ->where([
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId
            ]);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        // Apply additional filters if provided
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // Handle operators like 'gte', 'lte', etc.
                foreach ($value as $operator => $val) {
                    switch ($operator) {
                        case 'gte':
                            $query->whereRaw("{$field} >= ?", [$val]);
                            break;
                        case 'lte':
                            $query->whereRaw("{$field} <= ?", [$val]);
                            break;
                        case 'gt':
                            $query->whereRaw("{$field} > ?", [$val]);
                            break;
                        case 'lt':
                            $query->whereRaw("{$field} < ?", [$val]);
                            break;
                        case 'like':
                            $query->whereRaw("{$field} LIKE ?", ["%{$val}%"]);
                            break;
                        case 'in':
                            if (is_array($val) && !empty($val)) {
                                $placeholders = implode(',', array_fill(0, count($val), '?'));
                                $query->whereRaw("{$field} IN ({$placeholders})", $val);
                            }
                            break;
                    }
                }
            } else {
                $query->where([$field => $value]);
            }
        }

        $result = $query->get();
        return $result ? (int)$result[0]['count'] : 0;
    }

    /**
     * Mark all notifications as read for a recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return int Number of notifications updated
     */
    public function markAllAsRead(string $notifiableType, string $notifiableId, ?string $userId = null): int
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        // Get all unread notifications for this recipient
        $unreadNotifications = $this->db->select($this->table, ['*'])
            ->where([
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId
            ])
            ->whereNull('read_at')
            ->get();

        if (empty($unreadNotifications)) {
            return 0;
        }

        // Start a transaction for batch updates
        $this->beginTransaction();

        try {
            $updated = 0;

            // Update each notification individually to get proper audit logging
            foreach ($unreadNotifications as $notification) {
                $data = $notification;
                $data['read_at'] = $now;

                if ($this->update($data['uuid'], ['read_at' => $now], $userId)) {
                    $updated++;
                }
            }

            $this->commit();
            return $updated;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Delete old notifications
     *
     * @param int $olderThanDays Delete notifications older than this many days
     * @param int|null $limit Maximum number to delete (not supported in current implementation)
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null, ?string $userId = null): bool
    {
        $cutoffDate = (new DateTime())->modify("-$olderThanDays days")->format('Y-m-d H:i:s');

        // First find the notifications to delete (to ensure proper audit logging)
        $oldNotifications = $this->db->select($this->table, ['uuid'])
            ->whereRaw("created_at < ?", [$cutoffDate])
            ->limit($limit)
            ->get();

        if (empty($oldNotifications)) {
            return true;  // Nothing to delete
        }

        // Start a transaction for batch deletes
        $this->beginTransaction();

        try {
            $success = true;

            // Delete each notification individually to get proper audit logging
            foreach ($oldNotifications as $notification) {
                if (!$this->delete($notification['uuid'], $userId)) {
                    $success = false;
                }
            }

            $this->commit();
            return $success;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a single notification by UUID
     *
     * @param string $uuid The UUID of the notification to delete
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function deleteNotificationByUuid(string $uuid, ?string $userId = null): bool
    {
        // Use BaseRepository's delete method which handles audit logging
        return $this->delete($uuid, $userId);
    }
}
