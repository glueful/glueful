<?php

namespace Tests\Unit\Repository\Mocks;

use Glueful\Database\Connection;
use Glueful\Validation\Validator;
use Tests\Unit\Repository\Mocks\MockUserConnection;

/**
 * Test User Repository
 *
 * Standalone test repository that completely bypasses BaseRepository
 * to avoid any shared connection interference from other tests.
 */
class TestUserRepository
{
    /** @var Connection The test database connection */
    private Connection $testConnection;

    /** @var Validator|null The validator instance */
    private ?Validator $testValidator;

    /** @var string Table name */
    private string $table;

    /** @var string Primary key field */
    private string $primaryKey;

    /** @var array Default fields to retrieve */
    private array $defaultFields;

    /** @var array Standard user fields to retrieve */
    private array $userFields = ['uuid', 'username', 'email', 'password', 'status', 'created_at'];

    /**
     * Constructor with dependency injection support
     *
     * @param Connection|null $connection Optional connection to inject
     * @param Validator|null $validator Optional validator to inject
     */
    public function __construct(?Connection $connection = null, ?Validator $validator = null)
    {
        // Initialize properties
        $this->table = 'users';
        $this->primaryKey = 'uuid';
        $this->defaultFields = ['uuid', 'username', 'email', 'password', 'status', 'created_at'];

        // Set the connection - ALWAYS use our test connection
        if ($connection) {
            $this->testConnection = $connection;
        } else {
            $this->testConnection = new MockUserConnection();
        }

        // Set validator if provided
        $this->testValidator = $validator;
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

        // Use the test connection to insert data
        $result = $this->testConnection->table('users')->insert($userData);

        // The actual method returns the UUID if successful, throws exception otherwise
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

        // Query database for user using test connection
        $result = $this->testConnection->table('users')
            ->select($this->userFields)
            ->where('email', '=', $email)
            ->limit(1)
            ->get();

        if (!empty($result)) {
            return $result[0];
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
        // Query database for user using test connection
        $result = $this->testConnection->table('users')
            ->select($this->userFields)
            ->where('username', '=', $username)
            ->limit(1)
            ->get();

        if (!empty($result)) {
            return $result[0];
        }

        return null;
    }

    /**
     * Find user by UUID - overridden for testing
     *
     * @param string $uuid UUID to search for
     * @return array|null User data or null if not found
     */
    public function findByUuid(string $uuid, ?array $fields = null): ?array
    {
        // Query database using test connection
        $result = $this->testConnection->table('users')
            ->select($fields ?? $this->userFields)
            ->where('uuid', '=', $uuid)
            ->limit(1)
            ->get();

        if (!empty($result)) {
            return $result[0];
        }

        return null;
    }
}
