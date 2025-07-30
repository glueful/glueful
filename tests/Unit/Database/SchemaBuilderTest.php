<?php

namespace Tests\Unit\Database;

use Tests\TestCase;
use Tests\Unit\Database\Mocks\MockSQLiteConnection;

/**
 * SchemaBuilder Unit Tests
 */
class SchemaBuilderTest extends TestCase
{
    /**
     * @var MockSQLiteConnection
     */
    protected MockSQLiteConnection $connection;

    /**
     * Set up the test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock SQLite in-memory connection
        $this->connection = new MockSQLiteConnection();
    }

    /**
     * Test create table operation
     */
    public function testCreateTable(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        // Create a test table using new fluent API
        $schema->createTable('test_table', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
        });

        // Test the table exists by inserting data
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare("INSERT INTO test_table (name, email) VALUES (?, ?)");
        $success = $stmt->execute(['Test User', 'test@example.com']);

        $this->assertTrue($success);

        // Retrieve the data
        $stmt = $pdo->query("SELECT * FROM test_table");
        $row = $stmt->fetch();

        $this->assertEquals('Test User', $row['name']);
        $this->assertEquals('test@example.com', $row['email']);
    }

    /**
     * Test table with indexes
     */
    public function testTableWithIndexes(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        // Create a test table with indexes
        $schema->createTable('structure_test', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('email');
            $table->index('name');
            $table->index('active');
        });

        // Test the table exists and constraints work
        $pdo = $this->connection->getPDO();

        // Insert first user
        $stmt = $pdo->prepare("INSERT INTO structure_test (name, email, active) VALUES (?, ?, ?)");
        $success = $stmt->execute(['Test User', 'test@example.com', 1]);
        $this->assertTrue($success);

        // Try to insert duplicate email (should fail)
        try {
            $stmt->execute(['Another User', 'test@example.com', 1]);
            $this->fail('Unique constraint violation not detected');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
    }

    /**
     * Test foreign key relationships
     */
    public function testForeignKeys(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $pdo = $this->connection->getPDO();

        // Enable foreign key constraints on SQLite
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Create parent table
        $schema->createTable('users', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->unique('email');
        });

        // Create child table with foreign key
        $schema->createTable('posts', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->integer('user_id');
            $table->string('title', 255);
            $table->text('content');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        // Insert test data
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Test User', 'test@example.com')");
        $pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (1, 'Test Post', 'Test content')");

        // Test foreign key constraint - should fail
        try {
            $pdo->exec("INSERT INTO posts (user_id, title, content) VALUES (999, 'Orphan Post', 'Content')");
            $this->fail('Foreign key constraint violation not detected');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $e->getMessage());
        }

        // Test cascade delete
        $pdo->exec("DELETE FROM users WHERE id = 1");
        $stmt = $pdo->query("SELECT COUNT(*) FROM posts");
        $count = $stmt->fetchColumn();
        $this->assertEquals(0, $count, 'Posts should be deleted by CASCADE constraint');
    }

    /**
     * Test dropping tables
     */
    public function testDropTable(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $pdo = $this->connection->getPDO();

        // Create a test table
        $schema->createTable('drop_test', function ($table) {
            $table->integer('id')->primary()->autoIncrement();
            $table->string('name', 255);
        });

        // Check table exists
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
        $tableNames = array_column($tables, 'name');
        $this->assertContains('drop_test', $tableNames);

        // Drop the table
        $schema->dropTableIfExists('drop_test');

        // Check table no longer exists
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
        $tableNames = array_column($tables, 'name');
        $this->assertNotContains('drop_test', $tableNames);
    }
}
