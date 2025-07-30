<?php

namespace Tests\Unit\Repository\Mocks;

use Glueful\Repository\NotificationRepository;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;
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
     * @param Connection|null $connection Optional connection to inject
     */
    public function __construct(?Connection $connection = null)
    {
        // Skip parent constructor to avoid real database connection

        // Initialize properties that would normally be set in parent
        $this->table = 'notifications';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['*'];

        // Set the db property to connection
        if ($connection) {
            $this->db = $connection;
        } else {
            $connection = new MockSQLiteConnection();
            $this->db = $connection;
            
            // Create necessary tables for notification testing
            $connection->createNotificationTables();
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
            $result = $this->db->table($this->table)
                ->where($this->primaryKey, '=', $id)
                ->update($data);
            return $result > 0;
        }
        return false;
    }

    /**
     * Override findBy to avoid actual database calls
     */
    public function findBy(string $field, $value, ?array $fields = null): ?array
    {
        // Return complete test data for findTemplateByUuid test
        if ($field === 'uuid' && $value === 'template-uuid-123') {
            return [
                'id' => 'template-1',
                'uuid' => 'template-uuid-123',
                'name' => 'Welcome Email',
                'notification_type' => 'account_created',
                'channel' => 'email',
                'content' => 'Hello {{name}}, welcome to our application.',
                'parameters' => json_encode(['subject' => 'Welcome to {{app_name}}']),
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ];
        }

        // Return default dummy data for other tests
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

        // Parse parameters from JSON string or use empty array if not available
        $parameters = [];
        if (isset($data['parameters']) && !empty($data['parameters'])) {
            $parameters = json_decode($data['parameters'], true) ?: [];
        }

        // Create a hardcoded mock template instead of using PHPUnit's createMock()
        $template = new class (
            (string)($data['id'] ?? ''),
            $data['name'] ?? 'Mock Template Name',
            $data['notification_type'] ?? '',
            $data['channel'] ?? '',
            $data['content'] ?? '',
            $parameters,
            $data['uuid'] ?? ''
        ) extends \Glueful\Notifications\Models\NotificationTemplate {
            public function __construct(
                private string $mockId,
                private string $mockName,
                private string $mockNotificationType,
                private string $mockChannel,
                private string $mockContent,
                private array $mockParameters,
                private ?string $mockUuid
            ) {
                // Skip parent constructor to avoid type errors
            }

            public function getId(): string
            {
                return $this->mockId;
            }

            public function getName(): string
            {
                return $this->mockName;
            }

            public function getNotificationType(): string
            {
                return $this->mockNotificationType;
            }

            public function getChannel(): string
            {
                return $this->mockChannel;
            }

            public function getContent(): string
            {
                return $this->mockContent;
            }

            public function getParameters(): array
            {
                return $this->mockParameters;
            }

            public function getUuid(): ?string
            {
                return $this->mockUuid;
            }
        };

        return $template;
    }
    /**
     * Override the save method with mock implementation
     *
     * @param \Glueful\Notifications\Models\Notification $notification The notification to save
     * @param string|null $userId ID of user performing the action
     * @return bool Success status
     */
    public function save(\Glueful\Notifications\Models\Notification $notification, ?string $userId = null): bool
    {
        $data = $notification->toArray();

        // Convert data field to JSON
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }

        // Ensure UUID is present
        if (!isset($data['uuid']) || empty($data['uuid'])) {
            $data['uuid'] = 'mock-uuid-' . mt_rand(1000, 9999);
        }

        // If we have a configured db connection, use it
        if (isset($this->db)) {
            // Find existing by UUID using QueryBuilder
            $result = $this->db->table($this->table)
                ->where($this->primaryKey, '=', $data['uuid'])
                ->get();

            $existing = !empty($result);

            if ($existing) {
                // Update existing
                unset($data['id']); // Remove ID to avoid issues
                $this->db->table($this->table)
                    ->where($this->primaryKey, '=', $data['uuid'])
                    ->update($data);
            } else {
                // Insert new
                $this->db->table($this->table)->insert($data);
            }
        }

        // Always return true for test scenarios
        return true;
    }

    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null, ?string $userId = null): bool
    {
        // Handle the case where days might be null
        $olderThanDays = $olderThanDays ?? 30; // Default to 30 days if null

        // Cast $olderThanDays to int to avoid type issues
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . (int)$olderThanDays . ' days'));

        // First find the old notifications to delete
        $oldNotifications = $this->db->table($this->table)
            ->where('created_at', '<', $cutoff)
            ->get();

        if (empty($oldNotifications)) {
            return true; // Nothing to delete is success
        }

        // Delete old notifications using PDO directly
        $deletedCount = 0;
        foreach ($oldNotifications as $notification) {
            $stmt = $this->db->getPDO()->prepare("DELETE FROM {$this->table} WHERE uuid = ?");
            $success = $stmt->execute([$notification['uuid']]);
            if ($success && $stmt->rowCount() > 0) {
                $deletedCount++;
            }
        }

        return $deletedCount > 0;
    }

    /**
     * Override countForNotifiable to handle null values correctly
     */
    public function countForNotifiable(
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false,
        array $filters = []
    ): int {
        $query = $this->db->table($this->table)
            ->where('notifiable_type', '=', $notifiableType)
            ->where('notifiable_id', '=', $notifiableId);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }

        return $query->count();
    }

    /**
     * Override markAllAsRead to handle null values correctly
     */
    public function markAllAsRead(string $notifiableType, string $notifiableId, ?string $userId = null): int
    {
        // First find the unread notifications
        $unreadNotifications = $this->db->table($this->table)
            ->where('notifiable_type', '=', $notifiableType)
            ->where('notifiable_id', '=', $notifiableId)
            ->whereNull('read_at')
            ->get();

        if (empty($unreadNotifications)) {
            return 0;
        }

        // Update each notification individually to avoid complex WHERE clause issues
        $updatedCount = 0;
        foreach ($unreadNotifications as $notification) {
            $success = $this->db->table($this->table)
                ->where('uuid', '=', $notification['uuid'])
                ->update([
                    'read_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            if ($success) {
                $updatedCount++;
            }
        }

        return $updatedCount;
    }

    /**
     * Override delete method for testing
     */
    public function delete(string $uuid): bool
    {
        // Check if notification exists
        $existing = $this->db->table($this->table)
            ->where('uuid', '=', $uuid)
            ->get();

        if (empty($existing)) {
            return false;
        }

        // Use PDO directly to delete for testing reliability
        $stmt = $this->db->getPDO()->prepare("DELETE FROM {$this->table} WHERE uuid = ?");
        $success = $stmt->execute([$uuid]);
        
        return $success && $stmt->rowCount() > 0;
    }
}
