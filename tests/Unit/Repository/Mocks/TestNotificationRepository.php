<?php

namespace Tests\Unit\Repository\Mocks;

use Glueful\Repository\NotificationRepository;
use Glueful\Database\QueryBuilder;
use Tests\Unit\Database\Mocks\MockSQLiteConnection;

/**
 * Test Notification Repository
 *
 * Extends the real NotificationRepository but allows dependency injection
 * for easier testing.
 */
class TestNotificationRepository extends NotificationRepository
{
    /**
     * Constructor with dependency injection support
     *
     * @param QueryBuilder|null $queryBuilder Optional query builder to inject
     */
    public function __construct(?QueryBuilder $queryBuilder = null)
    {
        // Skip parent constructor to avoid real database connection

        // Initialize properties that would normally be set in parent
        $this->table = 'notifications';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['*'];
        $this->containsSensitiveData = false;
        
        // Set the db property (which was previously set to queryBuilder)
        $this->db = $queryBuilder;

        if ($queryBuilder) {
            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(NotificationRepository::class);
            $queryBuilderProperty = $reflection->getProperty('db');
            $queryBuilderProperty->setAccessible(true);
            $queryBuilderProperty->setValue($this, $queryBuilder);
        } else {
            $connection = new MockSQLiteConnection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(NotificationRepository::class);
            $queryBuilderProperty = $reflection->getProperty('db');
            $queryBuilderProperty->setAccessible(true);
            $queryBuilderProperty->setValue($this, $queryBuilder);

            // Create necessary tables for notification testing
            $this->setupNotificationTables($connection->getPDO());
        }
    }

    /**
     * Create tables needed for notification tests
     *
     * @param \PDO $pdo
     */
    private function setupNotificationTables(\PDO $pdo): void
    {
        // Create notifications table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            type TEXT NOT NULL,
            subject TEXT NOT NULL,
            content TEXT NULL,
            data TEXT NULL,
            priority TEXT DEFAULT 'normal',
            notifiable_type TEXT NOT NULL,
            notifiable_id TEXT NOT NULL,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");

        // Create notification_preferences table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            notifiable_type TEXT NOT NULL,
            notifiable_id TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            channels TEXT NOT NULL,
            enabled INTEGER DEFAULT 1,
            settings TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");

        // Create notification_templates table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            id TEXT PRIMARY KEY,
            uuid TEXT NOT NULL,
            name TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            channel TEXT NOT NULL,
            content TEXT NOT NULL,
            parameters TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");
    }

    /**
     * Override the update method for testing purposes
     */
    public function update($id, array $data, ?string $userId = null): bool
    {
        // For testing purposes, just proxy to the db update method
        if (isset($this->db)) {
            $result = $this->db->update($this->table, $data, [$this->primaryKey => $id]);
            return $result > 0;
        }
        return false;
    }

    /**
     * Override findBy to avoid actual database calls
     */
    public function findBy(string $field, $value, ?array $fields = null): ?array
    {
        // Return dummy data for testing
        return [
            'id' => 1,
            'uuid' => $value,
            'read_at' => null
        ];
    }

    /**
     * Override findByUuid method to use TestNotification
     * 
     * @param string $uuid Notification UUID
     * @return \Glueful\Notifications\Models\Notification|null The notification or null if not found
     */
    public function findByUuid(string $uuid): ?\Glueful\Notifications\Models\Notification
    {
        // Use BaseRepository's findBy method for consistent behavior
        $result = $this->findBy($this->primaryKey, $uuid);

        if (!$result) {
            return null;
        }

        // Use TestNotification instead of regular Notification
        return \Tests\Unit\Repository\Mocks\TestNotification::fromArray($result);
    }

    /**
     * Override findTemplateByUuid method to handle parameters correctly
     * 
     * @param string $uuid Template UUID
     * @return \Glueful\Notifications\Models\NotificationTemplate|null
     */
    public function findTemplateByUuid(string $uuid): ?\Glueful\Notifications\Models\NotificationTemplate
    {
        // Use BaseRepository's findBy method
        $data = $this->findBy('uuid', $uuid);

        if (!$data) {
            return null;
        }

        // Ensure parameters is a string or null before json_decode
        $parametersJson = $data['parameters'] ?? null;
        $parameters = $parametersJson !== null ? json_decode($parametersJson, true) : [];
        $parameters = $parameters ?? []; // Ensure we have at least an empty array

        return new \Glueful\Notifications\Models\NotificationTemplate(
            (string)$data['id'],
            $data['name'],
            $data['notification_type'],
            $data['channel'],
            $data['content'],
            $parameters,
            $data['uuid']
        );
    }

    /**
     * Override deleteOldNotifications to handle null limit
     * 
     * @param int $olderThanDays Delete notifications older than this many days
     * @param int|null $limit Maximum number to delete
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool
     */
    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null, ?string $userId = null): bool
    {
        // Handle the case where days might be null
        $olderThanDays = $olderThanDays ?? 30; // Default to 30 days if null
        
        // Cast $olderThanDays to int to avoid type issues
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . (int)$olderThanDays . ' days'));

        $this->db->where(['created_at' => ['<', $cutoff]]);
        
        // Explicitly cast to int to avoid issues in the mock
        $this->db->limit($limit !== null ? (int)$limit : (int)5000);
        
        $deleted = $this->db->delete($this->table, []);
        
        return $deleted ? true : false;
    }
}

