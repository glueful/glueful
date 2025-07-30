<?php

namespace Tests\Unit\Repository\Mocks;

use PDO;
use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;

/**
 * Mock SQLite Connection for Notification Repository Tests
 *
 * Provides an in-memory SQLite database for testing notification repository
 * operations without affecting actual databases.
 */
class MockNotificationConnection extends Connection
{
    /**
     * Create an in-memory SQLite database connection for testing
     */
    public function __construct()
    {
        // Create in-memory SQLite connection
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        // Set SQLite driver
        $this->driver = new SQLiteDriver($this->pdo);

        // Set SQLite schema builder
        $sqlGenerator = new SQLiteSqlGenerator();
        $this->schemaBuilder = new SchemaBuilder($this, $sqlGenerator);

        // Create the notification tables
        $this->createNotificationTables();
    }

    /**
     * Creates notification tables for testing
     */
    public function createNotificationTables(): void
    {
        // Create notifications table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
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
            scheduled_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            deleted_at TIMESTAMP NULL
        )");

        // Create notification_preferences table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
            id TEXT PRIMARY KEY,
            uuid TEXT NOT NULL,
            notifiable_type TEXT NOT NULL,
            notifiable_id TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            channels TEXT NOT NULL,
            enabled INTEGER DEFAULT 1,
            settings TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            deleted_at TIMESTAMP NULL
        )");

        // Create notification_templates table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            id TEXT PRIMARY KEY,
            uuid TEXT NOT NULL,
            name TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            channel TEXT NOT NULL,
            content TEXT NOT NULL,
            parameters TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL,
            deleted_at TIMESTAMP NULL
        )");
    }

    /**
     * Insert sample notification data for testing
     */
    public function insertSampleData(): void
    {
        // Insert sample notifications
        $this->pdo->exec("INSERT INTO notifications (uuid, type, subject, content, data, priority, 
                          notifiable_type, notifiable_id) VALUES 
            ('test-uuid-1', 'account_created', 'Welcome to Glueful', 'Welcome content', 
             '{\"key\":\"value\"}', 'normal', 'user', 'user-123'),
            ('test-uuid-2', 'password_changed', 'Password Changed', 'Your password was changed', 
             '{\"ip\":\"192.168.1.1\"}', 'high', 'user', 'user-123')");

        // Insert sample notification preferences
        $this->pdo->exec("INSERT INTO notification_preferences 
            (uuid, notifiable_type, notifiable_id, notification_type, channels, enabled, settings) VALUES 
            ('pref-uuid-1', 'user', 'user-123', 'account-updates', 
             '{\"email\",\"push\"}', 1, '{\"frequency\":\"immediate\"}'),
            ('pref-uuid-2', 'user', 'user-123', 'marketing', 
             '{\"email\"}', 0, '{\"frequency\":\"weekly\"}')");

        // Insert sample notification templates
        $this->pdo->exec("INSERT INTO notification_templates 
            (id, uuid, name, notification_type, channel, content, parameters) VALUES 
            ('template-1', 'template-uuid-1', 'Welcome Email', 'account_created', 'email', 
             'Hello {{name}}, welcome to our application.', '{\"subject\":\"Welcome to {{app_name}}\"}')");
    }
}
