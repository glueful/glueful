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

        // If we have a configured db mock, use it
        if (isset($this->db)) {
            // Find existing by UUID using array-based lookup to avoid circular dependencies
            $result = $this->db->select($this->table, ['*'])
                ->where([$this->primaryKey => $data['uuid']])
                ->get();

            $existing = !empty($result);

            if ($existing) {
                // Update existing
                unset($data['id']); // Remove ID to avoid issues
                $this->db->update($this->table, $data, [$this->primaryKey => $data['uuid']]);
            } else {
                // Insert new
                $this->db->insert($this->table, $data);
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

        $this->db->where(['created_at' => ['<', $cutoff]]);

        // Explicitly cast to int to avoid issues in the mock
        $this->db->limit($limit !== null ? (int)$limit : (int)5000);

        $deleted = $this->db->delete($this->table, []);

        return $deleted ? true : false;
    }
}
