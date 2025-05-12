<?php
namespace Tests\Unit\Database;

use Tests\TestCase;
use Glueful\Database\Connection;
use Tests\Unit\Database\Mocks\MockSQLiteConnection;

/**
 * SchemaManager Unit Tests
 */
class SchemaManagerTest extends TestCase
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
        $schema = $this->connection->getSchemaManager();
        
        // Create a test table
        $result = $schema->createTable('test_table', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'name' => 'TEXT NOT NULL',
            'email' => 'TEXT NOT NULL',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ]);
        
        // Assert the table was created
        $this->assertNotNull($result);
        
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
     * Test table structure inspection
     */
    public function testTableStructure(): void
    {
        $schema = $this->connection->getSchemaManager();
        
        // Create a test table
        $schema->createTable('structure_test', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'name' => 'TEXT NOT NULL',
            'active' => 'BOOLEAN DEFAULT 1',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ]);
        
        // Test the table structure
        $columns = $schema->getTableColumns('structure_test');
        
        // The structure should contain our columns
        $this->assertNotEmpty($columns);
        $this->assertCount(4, $columns);
        
        // Check column names
        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('active', $columnNames);
        $this->assertContains('created_at', $columnNames);
    }
    
    /**
     * Test adding indexes
     */
    public function testAddIndex(): void
    {
        $schema = $this->connection->getSchemaManager();
        
        // Create a test table
        $schema->createTable('index_test', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'name' => 'TEXT NOT NULL',
            'email' => 'TEXT NOT NULL'
        ]);
        
        // Add an index
        $result = $schema->addIndex([
            'type' => 'UNIQUE',
            'column' => 'email',
            'table' => 'index_test'
        ]);
        
        $this->assertNotNull($result);
        
        // Test the index by trying to insert duplicate emails
        $pdo = $this->connection->getPDO();
        $pdo->exec("INSERT INTO index_test (name, email) VALUES ('User 1', 'same@example.com')");
        
        // This should fail due to unique constraint
        try {
            $pdo->exec("INSERT INTO index_test (name, email) VALUES ('User 2', 'same@example.com')");
            $this->fail('Unique constraint violation not detected');
        } catch (\PDOException $e) {
            // This is expected - constraint violation
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
    }
    
    /**
     * Test adding a foreign key
     */
    public function testAddForeignKey(): void
    {
        $pdo = $this->connection->getPDO();
        $schema = $this->connection->getSchemaManager();
        
        // Enable foreign key constraints on SQLite
        $pdo->exec('PRAGMA foreign_keys = ON');
        
        // Create parent table
        $schema->createTable('parent_table', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'name' => 'TEXT NOT NULL'
        ]);
        
        // Create child table with foreign key
        $schema->createTable('child_table', [
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'parent_id' => 'INTEGER NOT NULL',
            'name' => 'TEXT NOT NULL'
        ])->addForeignKey([
            'column' => 'parent_id',
            'references' => 'id',
            'on' => 'parent_table',
            'onDelete' => 'CASCADE'
        ]);
        
        // Insert a parent record
        $pdo->exec("INSERT INTO parent_table (name) VALUES ('Parent 1')");
        
        // Insert a child record
        $pdo->exec("INSERT INTO child_table (parent_id, name) VALUES (1, 'Child 1')");
        
        // Test foreign key by trying to reference a non-existent parent
        try {
            $pdo->exec("INSERT INTO child_table (parent_id, name) VALUES (999, 'Orphan Child')");
            $this->fail('Foreign key constraint violation not detected');
        } catch (\PDOException $e) {
            // This is expected - constraint violation
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $e->getMessage());
        }
        
        // Test cascade delete
        $pdo->exec("DELETE FROM parent_table WHERE id = 1");
        
        // Child should be deleted too
        $stmt = $pdo->query("SELECT COUNT(*) FROM child_table");
        $count = $stmt->fetchColumn();
        
        $this->assertEquals(0, $count, 'Child record should be deleted by CASCADE constraint');
    }
    
    /**
     * Test dropping a table
     */
    public function testDropTable(): void
    {
        $schema = $this->connection->getSchemaManager();
        
        // Create a test table
        $schema->createTable('drop_test', [
            'id' => 'INTEGER PRIMARY KEY',
            'name' => 'TEXT'
        ]);
        
        // Check table exists
        $tables = $this->connection->getPDO()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
        $tableNames = array_column($tables, 'name');
        $this->assertContains('drop_test', $tableNames);
        
        // Drop the table
        $result = $schema->dropTable('drop_test');
        $this->assertNotNull($result);
        
        // Check table no longer exists
        $tables = $this->connection->getPDO()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();
        $tableNames = array_column($tables, 'name');
        $this->assertNotContains('drop_test', $tableNames);
    }
}
