<?php

namespace Tests;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Base TestCase for database-related tests
 * 
 * Provides common functionality for tests that require database access
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var Connection */
    protected Connection $connection;
    
    /** @var QueryBuilder */
    protected QueryBuilder $db;
    
    /**
     * Setup test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a new database connection for testing
        $this->connection = new Connection();
        $this->db = new QueryBuilder($this->connection->getPDO(), $this->connection->getDriver());
        
        // Run migrations to set up the test database schema
        $this->runMigrations();
    }
    
    /**
     * Run database migrations to set up the test database schema
     * 
     * Each test will start with a fresh database schema
     */
    protected function runMigrations(): void
    {
        // Implementation would connect to your migration system
        // For SQLite in-memory, you'd run all migrations from scratch
    }
    
    /**
     * Reset the database after each test
     */
    protected function tearDown(): void
    {
        // Clear all database tables
        $this->clearDatabase();
        
        parent::tearDown();
    }
    
    /**
     * Clear all database tables
     */
    protected function clearDatabase(): void
    {
        // Implementation would clear all tables
        // For SQLite in-memory, this might not be needed as the database disappears after the test
    }
    
    /**
     * Create test fixtures in the database
     * 
     * @param string $fixture Name of the fixture to load
     */
    protected function loadFixture(string $fixture): void
    {
        $fixtureFile = TEST_ROOT . '/Fixtures/' . $fixture . '.php';
        
        if (file_exists($fixtureFile)) {
            require $fixtureFile;
        }
    }
}