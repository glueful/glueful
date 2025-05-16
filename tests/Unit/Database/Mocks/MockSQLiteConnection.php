<?php
namespace Tests\Unit\Database\Mocks;

use PDO;
use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Schema\SQLiteSchemaManager;

/**
 * Mock SQLite Connection for Testing
 *
 * Provides an in-memory SQLite database for testing database operations
 * without affecting actual databases.
 */
class MockSQLiteConnection extends Connection
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
    }

    /**
     * Creates standard testing tables for database tests
     */
    public function createTestTables(): void
    {
        // Create users table
        $this->schemaManager->createTable('users', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'username' => 'TEXT NOT NULL',
            'email' => 'TEXT NOT NULL',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'deleted_at' => 'DATETIME NULL'
        ]);

        // Create posts table with foreign key
        $this->schemaManager->createTable('posts', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'user_id' => 'INTEGER NOT NULL',
            'title' => 'TEXT NOT NULL',
            'content' => 'TEXT NOT NULL',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'deleted_at' => 'DATETIME NULL'
        ])->addForeignKey([
            'column' => 'user_id',
            'references' => 'id',
            'on' => 'users',
            'onDelete' => 'CASCADE'
        ]);
    }

    /**
     * Insert sample data for testing
     */
    public function insertSampleData(): void
    {
        // Insert sample users
        $this->pdo->exec("INSERT INTO users (username, email) VALUES 
            ('john_doe', 'john@example.com'),
            ('jane_smith', 'jane@example.com'),
            ('bob_jones', 'bob@example.com')");

        // Insert sample posts
        $this->pdo->exec("INSERT INTO posts (user_id, title, content) VALUES 
            (1, 'First Post', 'This is the first post content'),
            (1, 'Second Post', 'This is the second post content'),
            (2, 'Jane''s Post', 'This is Jane''s post content')");
    }
}
