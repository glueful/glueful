<?php
declare(strict_types=1);

namespace Glueful\Repository;

use DateTime;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Models\NotificationTemplate;

/**
 * Notification Repository
 * 
 * Handles all database operations related to notifications:
 * - Persisting notifications to database
 * - Retrieving notifications by various criteria
 * - Managing notification preferences
 * - Storing and retrieving notification templates
 * 
 * Implements the repository pattern to abstract database operations
 * for the notification system components.
 * 
 * @package Glueful\Repository
 */
class NotificationRepository
{
    /** @var QueryBuilder Database query builder instance */
    private QueryBuilder $queryBuilder;

    /**
     * Initialize repository
     * 
     * Sets up database connection and dependencies
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
    }

    /**
     * Save a notification to the database
     * 
     * Creates or updates a notification record.
     * 
     * @param Notification $notification The notification to save
     * @return bool Success status
     */
    public function save(Notification $notification): bool
    {
        $data = $notification->toArray();
        
        // Convert data field to JSON
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }
        
        // Ensure UUID is present for new notifications
        if (!isset($data['uuid']) || empty($data['uuid'])) {
            $data['uuid'] = \Glueful\Helpers\Utils::generateNanoID();
        }
        
        // Check if notification exists by UUID
        $existing = null;
        if (!empty($data['uuid'])) {
            $existing = $this->findByUuid($data['uuid']);
        }
        
        if ($existing) {
            // Update existing notification
            $updateColumns = array_keys($data);
            return $this->queryBuilder->upsert(
                'notifications',
                [$data],
                $updateColumns
            ) > 0;
        } else {
            // Create new notification
            return $this->queryBuilder->insert('notifications', $data) > 0;
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
        $result = $this->queryBuilder->select('notifications', ['*'])
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();
            
        if (!$result || empty($result)) {
            return null;
        }
        
        return Notification::fromArray($result[0]);
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
        $query = $this->queryBuilder->select('notifications', ['*'])
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
                    switch($operator) {
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
        
        $query = $this->queryBuilder->select('notifications', ['*'])
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
     * @return bool Success status
     */
    public function savePreference(NotificationPreference $preference): bool
    {
        $data = [
            'id' => $preference->getId(),
            'uuid' => $preference->getUuid() ?? \Glueful\Helpers\Utils::generateNanoID(),
            'notifiable_type' => $preference->getNotifiableType(),
            'notifiable_id' => $preference->getNotifiableId(),
            'notification_type' => $preference->getNotificationType(),
            'channels' => json_encode($preference->getChannels()),
            'enabled' => $preference->isEnabled() ? 1 : 0,
            'settings' => json_encode($preference->getSettings()),
        ];
        
        // Check if preference exists by UUID
        $existing = null;
        if (!empty($preference->getUuid())) {
            $existing = $this->findPreferenceByUuid($preference->getUuid());
        }
        
        if ($existing) {
            // Update existing preference
            $updateColumns = array_keys($data);
            return $this->queryBuilder->upsert(
                'notification_preferences',
                [$data],
                $updateColumns
            ) > 0;
        } else {
            // Create new preference
            return $this->queryBuilder->insert('notification_preferences', $data) > 0;
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
        $result = $this->queryBuilder->select('notification_preferences', ['*'])
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();
            
        if (!$result || empty($result)) {
            return null;
        }
        
        $data = $result[0];
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
        $results = $this->queryBuilder->select('notification_preferences', ['*'])
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
    }
    
    /**
     * Save a notification template to the database
     * 
     * @param NotificationTemplate $template The template to save
     * @return bool Success status
     */
    public function saveTemplate(NotificationTemplate $template): bool
    {
        $data = [
            'id' => $template->getId(),
            'uuid' => $template->getUuid() ?? \Glueful\Helpers\Utils::generateNanoID(),
            'name' => $template->getName(),
            'notification_type' => $template->getNotificationType(),
            'channel' => $template->getChannel(),
            'content' => $template->getContent(),
            'parameters' => json_encode($template->getParameters()),
        ];
        
        // Check if template exists by UUID
        $existing = null;
        if (!empty($template->getUuid())) {
            $existing = $this->findTemplateByUuid($template->getUuid());
        }
        
        if ($existing) {
            // Update existing template
            $updateColumns = array_keys($data);
            return $this->queryBuilder->upsert(
                'notification_templates',
                [$data],
                $updateColumns
            ) > 0;
        } else {
            // Create new template
            return $this->queryBuilder->insert('notification_templates', $data) > 0;
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
        $result = $this->queryBuilder->select('notification_templates', ['*'])
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();
            
        if (!$result || empty($result)) {
            return null;
        }
        
        $data = $result[0];
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
        $results = $this->queryBuilder->select('notification_templates', ['*'])
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
        $query = $this->queryBuilder->select('notifications', ['COUNT(*) as count'])
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
                    switch($operator) {
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
     * @return int Number of notifications updated
     */
    public function markAllAsRead(string $notifiableType, string $notifiableId): int
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        
        // Get all unread notifications for this recipient
        $unreadNotifications = $this->queryBuilder->select('notifications', ['*'])
            ->where([
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId
            ])
            ->whereNull('read_at')
            ->get();
        
        if (empty($unreadNotifications)) {
            return 0;
        }
        
        // Prepare data for batch update using upsert
        $updateData = [];
        foreach ($unreadNotifications as $notification) {
            $notification['read_at'] = $now;
            $updateData[] = $notification;
        }
        
        // Update using upsert
        $affected = $this->queryBuilder->upsert(
            'notifications',
            $updateData,
            ['read_at']
        );
        
        return $affected;
    }
    
    /**
     * Delete old notifications
     * 
     * @param int $olderThanDays Delete notifications older than this many days
     * @param int|null $limit Maximum number to delete (not supported in current implementation)
     * @return bool Success status
     */
    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null): bool
    {
        $cutoffDate = (new DateTime())->modify("-$olderThanDays days")->format('Y-m-d H:i:s');
        
        $conditions = [
            "created_at < '$cutoffDate'" => null
        ];
        
        // Note: The QueryBuilder's delete method doesn't support a limit parameter in its signature
        // The third parameter is actually softDelete (bool), not limit
        return $this->queryBuilder->delete('notifications', $conditions);
    }
    
    /**
     * Delete a single notification by UUID
     *
     * @param string $uuid The UUID of the notification to delete
     * @return bool Success status
     */
    public function deleteNotificationByUuid(string $uuid): bool
    {
        $conditions = [
            'uuid' => $uuid
        ];
        
        return $this->queryBuilder->delete('notifications', $conditions);
    }
}