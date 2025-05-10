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
        
        // Create a new database connection for testing using our mock
        $this->connection = new \Tests\Mocks\MockConnection();
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
        $pdo = $this->connection->getPDO();
        
        // Create notifications table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");
        
        // Create notification_preferences table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            notifiable_type TEXT NOT NULL,
            notifiable_id TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            channels TEXT NOT NULL,
            enabled INTEGER DEFAULT 1,
            settings TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");
        
        // Create notification_templates table
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            id TEXT PRIMARY KEY,
            uuid TEXT NOT NULL,
            name TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            channel TEXT NOT NULL,
            content TEXT NOT NULL,
            parameters TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");
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