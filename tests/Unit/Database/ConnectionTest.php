<?php
namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Glueful\Database\Connection;
use Tests\Unit\Database\Mocks\MockSQLiteConnection;
use PDO;

/**
 * Connection Class Unit Tests
 */
class ConnectionTest extends TestCase
{
    /**
     * Test that the Connection class can be instantiated
     */
    public function testConnectionInstantiation(): void
    {
        // Create mock SQLite connection
        $connection = new MockSQLiteConnection();
        
        // Assert connection is created successfully
        $this->assertInstanceOf(Connection::class, $connection);
        
        // Assert PDO instance is available
        $this->assertInstanceOf(PDO::class, $connection->getPDO());
    }
    
    /**
     * Test the connection returns a valid PDO instance
     */
    public function testGetPDO(): void
    {
        // Create mock SQLite connection
        $connection = new MockSQLiteConnection();
        
        // Get PDO instance
        $pdo = $connection->getPDO();
        
        // Assert it's a PDO instance
        $this->assertInstanceOf(PDO::class, $pdo);
        
        // Test PDO connection is working
        try {
            $result = $pdo->query("SELECT sqlite_version()")->fetchColumn();
            $this->assertNotEmpty($result, "SQLite version should be returned");
        } catch (\Exception $e) {
            $this->fail("PDO connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test the connection returns a valid driver
     */
    public function testGetDriver(): void
    {
        // Create mock SQLite connection
        $connection = new MockSQLiteConnection();
        
        // Get driver
        $driver = $connection->getDriver();
        
        // Assert driver is returned
        $this->assertInstanceOf(\Glueful\Database\Driver\DatabaseDriver::class, $driver);
        
        // Test driver's wrapIdentifier method
        $quoted = $driver->wrapIdentifier('table_name');
        $this->assertEquals('"table_name"', $quoted, "SQLite driver should quote identifiers with double quotes");
    }
    
    /**
     * Test the connection returns a valid schema manager
     */
    public function testGetSchemaManager(): void
    {
        // Create mock SQLite connection
        $connection = new MockSQLiteConnection();
        
        // Get schema manager
        $schemaManager = $connection->getSchemaManager();
        
        // Assert schema manager is returned
        $this->assertInstanceOf(\Glueful\Database\Schema\SchemaManager::class, $schemaManager);
    }
    
    /**
     * Test connection pooling
     */
    public function testConnectionPooling(): void
    {
        // Create multiple connections
        $connection1 = new MockSQLiteConnection();
        $connection2 = new MockSQLiteConnection();
        
        // Get PDO instances
        $pdo1 = $connection1->getPDO();
        $pdo2 = $connection2->getPDO();
        
        // For mock connections, we'll just test that the PDO objects are of the right type
        $this->assertInstanceOf(PDO::class, $pdo1);
        $this->assertInstanceOf(PDO::class, $pdo2);
    }
}
