<?php
declare(strict_types=1);

namespace Tests\Mocks;

use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use PDO;

/**
 * Mock database connection for unit tests
 *
 * Provides an in-memory SQLite database for unit tests
 */
class MockConnection extends Connection
{
    /**
     * Create a new MockConnection instance
     */
    public function __construct()
    {
        // Instead of calling parent, we'll set up our own in-memory SQLite database
        $this->createInMemoryDatabase();
    }

    /**
     * Create an in-memory SQLite database for testing
     */
    private function createInMemoryDatabase(): void
    {
        // Create in-memory SQLite connection
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Set up SQLite driver
        $this->driver = new SQLiteDriver($this->pdo);
    }

    /**
     * Get PDO instance
     *
     * @return PDO
     */
    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get database driver
     *
     * @return \Glueful\Database\Driver\DatabaseDriver
     */
    public function getDriver(): \Glueful\Database\Driver\DatabaseDriver
    {
        return $this->driver;
    }
}
