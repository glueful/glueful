<?php
namespace Tests\Unit\Repository\Mocks;

use PDO;
use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Schema\SQLiteSchemaManager;

/**
 * Mock SQLite Connection for Permission Repository Tests
 *
 * Provides an in-memory SQLite database for testing permission repository
 * operations without affecting actual databases.
 */
class MockPermissionConnection extends Connection
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

        // Set SQLite schema manager
        $this->schemaManager = new SQLiteSchemaManager($this->pdo);

        // Create the permission tables
        $this->createPermissionTables();
    }

    /**
     * Creates permission tables for testing
     */
    public function createPermissionTables(): void
    {
        // Create permissions table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            role_uuid TEXT NOT NULL,
            model TEXT NOT NULL,
            permissions TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE(role_uuid, model)
        )");

        // Create roles table (needed for permission tests)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL UNIQUE,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        // Create user_roles_lookup table (needed for permission tests)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_roles_lookup (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_uuid TEXT NOT NULL,
            role_uuid TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_uuid, role_uuid)
        )");

        // Create user_permissions table (for direct user permissions)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            user_uuid TEXT NOT NULL,
            model TEXT NOT NULL,
            permissions TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE(user_uuid, model)
        )");

        // Insert sample test data
        $this->insertSampleData();
    }

    /**
     * Insert sample data for testing
     */
    public function insertSampleData(): void
    {
        // Insert sample roles
        $this->pdo->exec("INSERT INTO roles (uuid, name, description) VALUES 
            ('admin-uuid', 'admin', 'Administrator role'),
            ('editor-uuid', 'editor', 'Editor role'),
            ('user-uuid', 'user', 'Standard user role')");

        // Insert sample role permissions
        $this->pdo->exec("INSERT INTO role_permissions (uuid, role_uuid, model, permissions) VALUES 
            ('111aaaabbbcc', 'admin-uuid', 'api.primary.users', 'ABDC'),
            ('222bbbcccdd', 'admin-uuid', 'api.settings', 'A'),
            ('333cccdddee', 'editor-uuid', 'api.posts', 'ABC'),
            ('444dddeeeff', 'user-uuid', 'api.primary.users', 'B')");

        // Insert sample user-role assignments
        $this->pdo->exec("INSERT INTO user_roles_lookup (user_uuid, role_uuid) VALUES 
            ('user-123', 'admin-uuid'),
            ('user-456', 'editor-uuid'),
            ('user-789', 'user-uuid')");

        // Insert sample user-specific permissions
        $this->pdo->exec("INSERT INTO user_permissions (uuid, user_uuid, model, permissions) VALUES 
            ('555eeefffgg', 'user-123', 'api.special', 'AB'),
            ('666fffggghi', 'user-456', 'api.custom', 'BC')");
    }
}
