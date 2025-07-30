<?php

namespace Tests\Unit\Database\Mocks;

use PDO;
use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Generators\SQLiteSqlGenerator;

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

        // Set SQLite schema builder
        $sqlGenerator = new SQLiteSqlGenerator();
        $this->schemaBuilder = new SchemaBuilder($this, $sqlGenerator);
    }

    /**
     * Creates standard testing tables for database tests
     */
    public function createTestTables(): void
    {
        // Drop tables first if they exist
        $this->pdo->exec('DROP TABLE IF EXISTS posts');
        $this->pdo->exec('DROP TABLE IF EXISTS users');

        // Create users table
        $this->schemaBuilder->createTable('users', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->string('username', 255);
            $table->string('email', 255);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
        });

        // Create posts table with foreign key
        $this->schemaBuilder->createTable('posts', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('user_id');
            $table->string('title', 255);
            $table->text('content');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
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
