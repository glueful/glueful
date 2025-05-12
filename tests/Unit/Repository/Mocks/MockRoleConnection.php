<?php
namespace Tests\Unit\Repository\Mocks;

use PDO;
use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Schema\SQLiteSchemaManager;

/**
 * Mock SQLite Connection for Role Repository Tests
 * 
 * Provides an in-memory SQLite database for testing role repository
 * operations without affecting actual databases.
 */
class MockRoleConnection extends Connection
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
        
        // Create the role tables
        $this->createRoleTables();
    }
    
    /**
     * Creates role tables for testing
     */
    public function createRoleTables(): void
    {
        // Create roles table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL UNIQUE,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");
        
        // Create user_roles_lookup table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_roles_lookup (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_uuid TEXT NOT NULL,
            role_uuid TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_uuid, role_uuid)
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
            ('11111111-1111-1111-1111-111111111111', 'admin', 'Administrator role'),
            ('22222222-2222-2222-2222-222222222222', 'editor', 'Editor role'),
            ('33333333-3333-3333-3333-333333333333', 'user', 'Standard user role')");
            
        // Insert sample user-role assignments
        $this->pdo->exec("INSERT INTO user_roles_lookup (user_uuid, role_uuid) VALUES 
            ('user-uuid', '11111111-1111-1111-1111-111111111111'),
            ('user-uuid', '22222222-2222-2222-2222-222222222222')");
    }
}
