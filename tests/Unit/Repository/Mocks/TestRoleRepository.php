<?php
namespace Tests\Unit\Repository\Mocks;

use Glueful\Repository\RoleRepository;
use Glueful\Database\QueryBuilder;

/**
 * Test Role Repository
 *
 * Extends the real RoleRepository but allows dependency injection
 * for easier testing.
 */
class TestRoleRepository extends RoleRepository
{
    /**
     * @var QueryBuilder Direct access to query builder for test methods
     */
    protected QueryBuilder $testDb;

    /**
     * Constructor with dependency injection support
     *
     * @param QueryBuilder|null $queryBuilder Optional query builder to inject
     */
    public function __construct(?QueryBuilder $queryBuilder = null)
    {
        // Skip parent constructor to avoid real database connection

        if ($queryBuilder) {
            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(RoleRepository::class);
            $dbProperty = $reflection->getProperty('db');
            $dbProperty->setAccessible(true);
            $dbProperty->setValue($this, $queryBuilder);

            // Also save reference for our test methods
            $this->testDb = $queryBuilder;
        } else {
            // Create mock connection with in-memory SQLite
            $connection = new MockRoleConnection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(RoleRepository::class);
            $dbProperty = $reflection->getProperty('db');
            $dbProperty->setAccessible(true);
            $dbProperty->setValue($this, $queryBuilder);

            // Also save reference for our test methods
            $this->testDb = $queryBuilder;
        }
    }

    // All database setup is now handled by MockRoleConnection

    /**
     * Override assignRole to avoid PermissionManager dependency
     *
     * @param string $userUuid User UUID to assign role to
     * @param string $roleUuid Role UUID to assign
     * @return bool Success status
     */
    public function assignRole(string $userUuid, string $roleUuid): bool
    {
        $data = [
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid,
        ];

        $result = $this->testDb->insert('user_roles_lookup', $data);
        return $result ? true : false;
    }

    /**
     * Override unassignRole to avoid PermissionManager dependency
     *
     * @param string $userUuid User UUID to remove role from
     * @param string $roleUuid Role UUID to remove
     * @return bool Success status
     */
    public function unassignRole(string $userUuid, string $roleUuid): bool
    {
        $result = $this->testDb->delete('user_roles_lookup', [
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid
        ]);

        return $result ? true : false;
    }
}
