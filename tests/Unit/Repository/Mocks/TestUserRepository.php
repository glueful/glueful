<?php

namespace Tests\Unit\Repository\Mocks;

use Glueful\Repository\UserRepository;
use Glueful\Database\QueryBuilder;
use Glueful\Validation\Validator;
use Tests\Unit\Repository\Mocks\MockUserConnection;
use Glueful\Logging\AuditLogger;

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
        // Initialize required properties without calling parent constructor
        $this->table = 'users';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['uuid', 'username', 'email', 'password', 'status', 'created_at'];
        $this->containsSensitiveData = true;
        $this->sensitiveFields = ['password', 'api_key', 'remember_token', 'reset_token'];

        if ($queryBuilder || $validator) {
            // Use reflection to set the properties in the parent class
            $reflection = new \ReflectionClass(UserRepository::class);
            $baseReflection = new \ReflectionClass(get_parent_class($reflection->getName()));

            if ($queryBuilder) {
                $this->testDb = $queryBuilder;
                // Set the db property (QueryBuilder)
                $dbProperty = $baseReflection->getProperty('db');
                $dbProperty->setAccessible(true);
                $dbProperty->setValue($this, $queryBuilder);
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

            // Use reflection to set properties
            $reflection = new \ReflectionClass(UserRepository::class);
            $baseReflection = new \ReflectionClass(get_parent_class($reflection->getName()));

            // Set the db property (QueryBuilder)
            $dbProperty = $baseReflection->getProperty('db');
            $dbProperty->setAccessible(true);
            $dbProperty->setValue($this, $queryBuilder);

            $validatorProperty = $reflection->getProperty('validator');
            $validatorProperty->setAccessible(true);
            $validatorProperty->setValue($this, $validator);
        }

        // Create a mock AuditLogger for testing
        $this->auditLogger = $this->createMockAuditLogger();
    }

    /**
     * Create new user - overridden for testing
     *
     * @param array $userData User data (username, email, password, etc.)
     * @return string New user UUID
     */
    public function create(array $userData): string
    {
        // Ensure required fields are present
        if (!isset($userData['username']) || !isset($userData['email']) || !isset($userData['password'])) {
            throw new \InvalidArgumentException('Required fields missing');
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

        // The actual method returns the UUID if successful, throws exception otherwise
        // The insert method may return int (row count) or bool depending on implementation
        if ($result === false || $result === 0) {
            throw new \RuntimeException('Failed to create user');
        }

        return $userData['uuid'];
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
     * Find user by username - overridden for testing
     *
     * @param string $username Username to search for
     * @return array|null User data or null if not found, or validation errors array
     */
    public function findByUsername(string $username): ?array
    {
        // Query database for user
        $query = $this->testDb->select('users', $this->userFields)
            ->where(['username' => $username])
            ->limit(1)
            ->get();

        if (!empty($query)) {
            return $query[0];
        }

        return null;
    }

    /**
     * Find user by UUID - overridden for testing
     *
     * @param string $uuid UUID to search for
     * @return array|null User data or null if not found
     */
    public function findByUUID(string $uuid): ?array
    {
        // Query database for user
        $query = $this->testDb->select('users', $this->userFields)
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();

        if (!empty($query)) {
            return $query[0];
        }

        return null;
    }

    /**
     * Create a mock AuditLogger instance for testing
     *
     * @return AuditLogger
     */
    private function createMockAuditLogger(): AuditLogger
    {
        // Create a minimal implementation of AuditLogger that does nothing during tests
        return new class extends AuditLogger
        {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function log($level, $message, array $context = []): void
            {
                // Do nothing in tests
                return;
            }
        };
    }

    /**
     * Expose the mock AuditLogger for testing
     *
     * @return \Glueful\Logging\AuditLogger
     */
    public function createMockAuditLoggerForTest()
    {
        return $this->createMockAuditLogger();
    }

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
