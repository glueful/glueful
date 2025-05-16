<?php
namespace Tests\Unit\Repository\Mocks;

use Glueful\Repository\UserRepository;
use Glueful\Database\QueryBuilder;
use Glueful\Validation\Validator;
use Tests\Unit\Database\Mocks\MockSQLiteConnection;

/**
 * Test User Repository
 *
 * Extends the real UserRepository but allows dependency injection
 * for easier testing. Provides proper implementation for test mocks.
 */
class TestUserRepository extends UserRepository
{
    /**
     * @var QueryBuilder The injected query builder
     */
    protected QueryBuilder $testDb;

    /**
     * @var Validator The injected validator
     */
    protected Validator $testValidator;

    /**
     * @var array Standard user fields to retrieve
     */
    private array $userFields = ['uuid', 'username', 'email', 'password', 'status', 'created_at'];

    /**
     * Constructor with dependency injection support
     *
     * @param QueryBuilder|null $queryBuilder Optional query builder to inject
     * @param Validator|null $validator Optional validator to inject
     */
    public function __construct(?QueryBuilder $queryBuilder = null, ?Validator $validator = null)
    {
        // Skip parent constructor to avoid real database connection

        if ($queryBuilder || $validator) {
            // Use reflection to set the private properties in the parent class
            $reflection = new \ReflectionClass(UserRepository::class);

            if ($queryBuilder) {
                $this->testDb = $queryBuilder;
                $queryBuilderProperty = $reflection->getProperty('queryBuilder');
                $queryBuilderProperty->setAccessible(true);
                $queryBuilderProperty->setValue($this, $queryBuilder);
            }

            if ($validator) {
                $this->testValidator = $validator;
                $validatorProperty = $reflection->getProperty('validator');
                $validatorProperty->setAccessible(true);
                $validatorProperty->setValue($this, $validator);
            }
        } else {
            $connection = new MockUserConnection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
            $validator = new Validator();

            $this->testDb = $queryBuilder;
            $this->testValidator = $validator;

            // Use reflection to set the private properties in the parent class
            $reflection = new \ReflectionClass(UserRepository::class);

            $queryBuilderProperty = $reflection->getProperty('queryBuilder');
            $queryBuilderProperty->setAccessible(true);
            $queryBuilderProperty->setValue($this, $queryBuilder);

            $validatorProperty = $reflection->getProperty('validator');
            $validatorProperty->setAccessible(true);
            $validatorProperty->setValue($this, $validator);

            // Setup user tables for testing
            $this->setupUserTables($connection->getPDO());
        }
    }

    /**
     * Create new user - overridden for testing
     *
     * @param array $userData User data (username, email, password, etc.)
     * @return string|null New user UUID or null on failure
     */
    public function create(array $userData): ?string
    {
        // Ensure required fields are present
        if (!isset($userData['username']) || !isset($userData['email']) || !isset($userData['password'])) {
            return null;
        }

        // Validate username and email - in test version we skip actual validation
        // but maintain compatibility with parent method

        // Set default values for optional fields
        $userData['status'] = $userData['status'] ?? 'active';
        $userData['created_at'] = $userData['created_at'] ?? date('Y-m-d H:i:s');

        // Generate UUID if not provided
        if (!isset($userData['uuid'])) {
            $userData['uuid'] = \Glueful\Helpers\Utils::generateNanoID();
        }

        // In the test version, insert method returns int in real code but might return bool in tests
        // We ensure we're always returning the UUID string or null as required by the method signature
        $result = $this->testDb->insert('users', $userData);

        // The actual method returns the UUID if successful, null otherwise
        // The insert method may return int (row count) or bool depending on implementation
        return ($result !== false && $result !== 0) ? $userData['uuid'] : null;
    }

    /**
     * Find user by email - overridden for testing
     *
     * @param string $email Email address to search for
     * @return array|null User data or null if not found, or validation errors array
     */
    public function findByEmail(string $email): ?array
    {
        // In the real implementation, validation happens first and can return an error array
        // We'll mimic that behavior for testing

        // Query database for user
        $query = $this->testDb->select('users', $this->userFields)
            ->where(['email' => $email])
            ->limit(1)
            ->get();

        if (!empty($query)) {
            return $query[0];
        }

        return null;
    }

    /**
     * Create tables needed for user tests
     *
     * @param \PDO $pdo
     */
    private function setupUserTables(\PDO $pdo): void
    {
        // Create users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, role_id)
        )");

        // Create roles table
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL UNIQUE,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL
        )");
    }
}
