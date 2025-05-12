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
        
        if ($queryBuilder) {
            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(NotificationRepository::class);
            $queryBuilderProperty = $reflection->getProperty('queryBuilder');
            $queryBuilderProperty->setAccessible(true);
            $queryBuilderProperty->setValue($this, $queryBuilder);
        } else {
            $connection = new MockSQLiteConnection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
            
            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(NotificationRepository::class);
            $queryBuilderProperty = $reflection->getProperty('queryBuilder');
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
}
