<?php
namespace Tests\Unit\Database;

use Tests\TestCase;
use Tests\Unit\Database\Mocks\MockSQLiteConnection;
use Glueful\Database\QueryBuilder;

/**
 * Base test case for database tests using SQLite in-memory database
 */
class SQLiteTestCase extends TestCase
{
    /** @var MockSQLiteConnection */
    protected MockSQLiteConnection $connection;

    /** @var QueryBuilder */
    protected QueryBuilder $db;

    /**
     * Set up the test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create SQLite in-memory database
        $this->connection = new MockSQLiteConnection();

        // Create query builder with soft deletes disabled for testing
        $pdo = $this->connection->getPDO();
        $driver = $this->connection->getDriver();
        $this->db = new QueryBuilder($pdo, $driver);

        // Disable soft deletes for testing
        $this->setPrivateProperty($this->db, 'softDeletes', false);

        // Create test tables
        $this->connection->createTestTables();
    }

    /**
     * Set a private property on an object using reflection
     *
     * @param object $object The object to modify
     * @param string $propertyName The name of the property
     * @param mixed $value The new value
     * @return void
     */
    protected function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * Insert sample data for testing
     */
    protected function insertSampleData(): void
    {
        $this->connection->insertSampleData();
    }

    /**
     * Clean up after the test
     */
    protected function tearDown(): void
    {
        // SQLite in-memory database is automatically destroyed
        // when the connection is closed, but we'll explicitly
        // close the connection to be sure
        $this->connection->getPDO()->exec('PRAGMA foreign_keys = OFF');
        $this->connection->getPDO()->exec('DROP TABLE IF EXISTS posts');
        $this->connection->getPDO()->exec('DROP TABLE IF EXISTS users');

        parent::tearDown();
    }
}
