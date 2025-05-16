<?php
namespace Tests\Unit\Repository\Mocks;

use PDO;
use Glueful\Database\Connection;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Schema\SQLiteSchemaManager;

/**
 * Mock SQLite Connection for User Repository Tests
 *
 * Provides an in-memory SQLite database for testing user repository
 * operations without affecting actual databases.
 */
class MockUserConnection extends Connection
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

        // Create the user tables
        $this->createUserTables();
    }

    /**
     * Creates user tables for testing
     */
    public function createUserTables(): void
    {
        // Create users table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            first_name TEXT NULL,
            last_name TEXT NULL,
            status TEXT DEFAULT 'active',
            email_verified INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");

        // Create user_roles table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, role_id)
        )");

        // Create roles table for user role tests
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL UNIQUE,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");
    }

    /**
     * Seed test users
     *
     * @param array $data Optional array of user data to insert
     * @return bool True on success
     */
    public function seedUsers(array $data = []): bool
    {
        if (empty($data)) {
            // Default test data
            $data = [
                [
                    'uuid' => 'test-uuid-1',
                    'username' => 'testuser',
                    'email' => 'test@example.com',
                    'password' => password_hash('password123', PASSWORD_DEFAULT),
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'status' => 'active',
                    'email_verified' => 1,
                    'created_at' => '2023-01-01 00:00:00',
                    'updated_at' => null
                ],
                [
                    'uuid' => 'test-uuid-2',
                    'username' => 'admin',
                    'email' => 'admin@example.com',
                    'password' => password_hash('adminpass', PASSWORD_DEFAULT),
                    'first_name' => 'Admin',
                    'last_name' => 'User',
                    'status' => 'active',
                    'email_verified' => 1,
                    'created_at' => '2023-01-01 00:00:00',
                    'updated_at' => null
                ]
            ];
        }

        // Prepare and execute insert statements
        $stmt = $this->pdo->prepare("
            INSERT INTO users (
                uuid, username, email, password, first_name, last_name, 
                status, email_verified, created_at, updated_at
            ) VALUES (
                :uuid, :username, :email, :password, :first_name, :last_name,
                :status, :email_verified, :created_at, :updated_at
            )
        ");

        foreach ($data as $record) {
            $stmt->execute($record);
        }

        return true;
    }

    /**
     * Seed test roles
     *
     * @param array $data Optional array of role data to insert
     * @return bool True on success
     */
    public function seedRoles(array $data = []): bool
    {
        if (empty($data)) {
            // Default test data
            $data = [
                [
                    'uuid' => 'role-uuid-1',
                    'name' => 'admin',
                    'description' => 'Administrator role',
                    'created_at' => '2023-01-01 00:00:00',
                    'updated_at' => null
                ],
                [
                    'uuid' => 'role-uuid-2',
                    'name' => 'user',
                    'description' => 'Regular user role',
                    'created_at' => '2023-01-01 00:00:00',
                    'updated_at' => null
                ]
            ];
        }

        // Prepare and execute insert statements
        $stmt = $this->pdo->prepare("
            INSERT INTO roles (
                uuid, name, description, created_at, updated_at
            ) VALUES (
                :uuid, :name, :description, :created_at, :updated_at
            )
        ");

        foreach ($data as $record) {
            $stmt->execute($record);
        }

        return true;
    }

    /**
     * Assign roles to users for testing
     *
     * @param array $assignments Array of user_id => role_id mappings
     * @return bool True on success
     */
    public function assignUserRoles(array $assignments = []): bool
    {
        if (empty($assignments)) {
            // Default: assign admin role to admin user and user role to test user
            $assignments = [
                ['user_id' => 1, 'role_id' => 2], // testuser -> user role
                ['user_id' => 2, 'role_id' => 1]  // admin -> admin role
            ];
        }

        // Prepare and execute insert statements
        $stmt = $this->pdo->prepare("
            INSERT INTO user_roles (user_id, role_id, created_at)
            VALUES (:user_id, :role_id, CURRENT_TIMESTAMP)
        ");

        foreach ($assignments as $assignment) {
            $stmt->execute($assignment);
        }

        return true;
    }
}
