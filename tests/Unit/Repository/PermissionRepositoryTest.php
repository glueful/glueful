<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use Tests\Unit\Repository\Mocks\TestPermissionRepository;
use Tests\Unit\Repository\Mocks\TestRoleRepository;
use Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Glueful\Database\QueryBuilder;

/**
 * Permission Repository Test
 *
 * Tests for the PermissionRepository class functionality including:
 * - Permission retrieval by role
 * - Permission assignment and verification
 * - User permission checks
 */
class PermissionRepositoryTest extends TestCase
{
    /** @var TestPermissionRepository */
    private TestPermissionRepository $permissionRepository;

    /** @var MockObject|QueryBuilder */
    private $mockQueryBuilder;

    /** @var MockObject|TestRoleRepository */
    private $mockRoleRepository;

    /**
     * Setup before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects
        $this->mockQueryBuilder = $this->createMock(QueryBuilder::class);

        // Create mock role repository
        $this->mockRoleRepository = $this->createMock(TestRoleRepository::class);

        // Create repository with our mock query builder
        $this->permissionRepository = new TestPermissionRepository(
            $this->mockQueryBuilder instanceof QueryBuilder ? $this->mockQueryBuilder : null,
            $this->mockRoleRepository instanceof TestRoleRepository ? $this->mockRoleRepository : null
        );
    }

    /**
     * Test getting permissions for a role
     */
    public function testGetRolePermissions(): void
    {
        // Sample permission data matching the table schema in migration
        $rawData = [
            [
                'uuid' => '111aaaabbbcc',
                'role_uuid' => 'admin-uuid-123',
                'model' => 'api.primary.users',
                'permissions' => 'ABDC',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            [
                'uuid' => '222bbbcccdd',
                'role_uuid' => 'admin-uuid-123',
                'model' => 'api.settings',
                'permissions' => 'A',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            [
                'uuid' => '333cccdddee',
                'role_uuid' => 'admin-uuid-123',
                'model' => 'api.files',
                'permissions' => 'AB',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ]
        ];

        // Expected formatted result
        $expected = [
            'api.primary.users' => [
                'permissions' => 'ABDC',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            'api.settings' => [
                'permissions' => 'A',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            'api.files' => [
                'permissions' => 'AB',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ]
        ];

        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn($rawData);

        // Call the method
        $result = $this->permissionRepository->getRolePermissions('admin');

        // Assert result
        $this->assertEquals($expected, $result);
    }

    /**
     * Test checking if a role has a specific permission
     */
    public function testCheckRolePermissionExists(): void
    {
        // Configure mock to return different values for different roles
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [ // First call for 'admin-uuid' role
                    [
                        'uuid' => '111aaaabbbcc',
                        'role_uuid' => 'admin-uuid',
                        'model' => 'api.primary.users',
                        'permissions' => 'ABDC',
                        'created_at' => '2023-01-01 00:00:00',
                        'updated_at' => null
                    ],
                    [
                        'uuid' => '222bbbcccdd',
                        'role_uuid' => 'admin-uuid',
                        'model' => 'api.settings',
                        'permissions' => 'A',
                        'created_at' => '2023-01-01 00:00:00',
                        'updated_at' => null
                    ]
                ],
                [ // Second call for 'user-uuid' role
                    [
                        'uuid' => '333cccdddee',
                        'role_uuid' => 'user-uuid',
                        'model' => 'api.primary.users',
                        'permissions' => 'ABC', // No Delete permission
                        'created_at' => '2023-01-01 00:00:00',
                        'updated_at' => null
                    ]
                ]
            );

        // Test role has 'B' permission on 'api.primary.users' model
        $adminPermissions = $this->permissionRepository->getRolePermissions('admin-uuid');
        $this->assertTrue(
            isset($adminPermissions['api.primary.users']) &&
            strpos($adminPermissions['api.primary.users']['permissions'], 'B') !== false
        );

        // Test role doesn't have 'D' permission on 'api.primary.users' model
        $userPermissions = $this->permissionRepository->getRolePermissions('user-uuid');
        $this->assertFalse(
            isset($userPermissions['api.primary.users']) &&
            strpos($userPermissions['api.primary.users']['permissions'], 'D') !== false
        );
    }

    /**
     * Test assigning a permission to a role
     */
    public function testAssignRolePermission(): void
    {
        // Configure query builder for successful insert
        $this->mockQueryBuilder->method('insert')
            ->willReturn(1);  // Return int 1 for 1 affected row

        // Call the method
        $roleUuid = 'role-uuid-123';
        $model = 'api.primary.users';
        $permissions = 'ABDC';

        $result = $this->permissionRepository->assignRolePermission($roleUuid, $model, $permissions);

        // Assert result
        $this->assertNotFalse($result);
        $this->assertIsString($result); // Should return the UUID of the new permission
    }

    /**
     * Test removing a permission from a role
     */
    public function testRemoveRolePermission(): void
    {
        // Configure query builder for successful delete
        $this->mockQueryBuilder->method('delete')
            ->willReturn(true);  // Return boolean

        // Call the method
        $roleUuid = 'role-uuid-123';
        $model = 'api.primary.users';

        $result = $this->permissionRepository->removeRolePermission($roleUuid, $model);

        // Assert result
        $this->assertTrue($result);
    }

    /**
     * Test getting all permissions in the system
     */
    public function testGetAllPermissions(): void
    {
        // Sample permission data matching the schema for role_permissions table
        $permissionsData = [
            [
                'uuid' => '111aaaabbbcc',
                'role_uuid' => 'admin-uuid-123',
                'model' => 'api.primary.users',
                'permissions' => 'ABDC',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            [
                'uuid' => '222bbbcccdd',
                'role_uuid' => 'admin-uuid-123',
                'model' => 'api.settings',
                'permissions' => 'A',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ],
            [
                'uuid' => '333cccdddee',
                'role_uuid' => 'editor-uuid-456',
                'model' => 'api.posts',
                'permissions' => 'ABC',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => null
            ]
        ];

        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn($permissionsData);

        // Call the method
        $result = $this->permissionRepository->getAllPermissions();

        // Assert result
        $this->assertEquals($permissionsData, $result);
    }

    /**
     * Test checking if a user has a specific permission
     */
    public function testHasPermission(): void
    {
        // Mock getUserPermissions to return empty array (causing it to check role permissions)
        $this->mockQueryBuilder
            ->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder
            ->method('where')
            ->willReturnSelf();

        // Configure query builder to return test data for getUserPermissions
        $this->mockQueryBuilder
            ->expects($this->exactly(2)) // Called twice for our two test scenarios
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [], // First getUserPermissions returns empty
                [] // Second getUserPermissions returns empty
            );

        // Mock role repository to return roles for the user
        $this->mockRoleRepository
            ->method('getUserRoles')
            ->willReturn([
                ['role_uuid' => 'role-1']
            ]);

        // Mock the role permissions
        $this->mockRoleRepository
            ->expects($this->exactly(2))
            ->method('getRolePermissions')
            ->willReturn([
                'api.primary.users' => [
                    'permissions' => 'AB',  // Admin (A), Basic (B) permissions
                    'created_at' => '2023-01-01 00:00:00',
                    'updated_at' => null
                ]
            ]);

        // Test user has B permission
        $result1 = $this->permissionRepository->hasPermission('user-uuid', 'api.primary.users', 'B');
        $this->assertTrue($result1);

        // Test user doesn't have D permission
        $result2 = $this->permissionRepository->hasPermission('user-uuid', 'api.primary.users', 'D');
        $this->assertFalse($result2);
    }
}
