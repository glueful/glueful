<?php
declare(strict_types=1);

namespace Tests\Unit\Repository;

use Tests\Unit\Repository\Mocks\TestRoleRepository;
use Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Glueful\Database\QueryBuilder;

/**
 * Role Repository Test
 * 
 * Tests for the RoleRepository class functionality including:
 * - Role retrieval operations
 * - Role-based access control (RBAC) functionality
 * - Permission assignment and verification
 */
class RoleRepositoryTest extends TestCase
{
    /** @var TestRoleRepository */
    private TestRoleRepository $roleRepository;
    
    /**
     * @var MockObject&QueryBuilder
     */
    private $mockQueryBuilder;
    
    /**
     * Setup before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock objects
        $this->mockQueryBuilder = $this->createMock(QueryBuilder::class);
        
        // Create repository with our mock query builder
        $this->roleRepository = new TestRoleRepository($this->mockQueryBuilder);
    }
    
    /**
     * Test getting all roles
     */
    public function testGetRoles(): void
    {
        // Sample role data
        $rolesData = [
            [
                'uuid' => '11111111-1111-1111-1111-111111111111',
                'name' => 'admin',
                'description' => 'Administrator role'
            ],
            [
                'uuid' => '22222222-2222-2222-2222-222222222222',
                'name' => 'editor',
                'description' => 'Editor role'
            ],
            [
                'uuid' => '33333333-3333-3333-3333-333333333333',
                'name' => 'user',
                'description' => 'Standard user role'
            ]
        ];
        
        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn($rolesData);
            
        // Call the method
        $result = $this->roleRepository->getRoles();
        
        // Assert result
        $this->assertEquals($rolesData, $result);
    }
    
    /**
     * Test getting role by UUID
     */
    public function testGetRoleByUUID(): void
    {
        // Sample role data
        $roleData = [
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'name' => 'admin',
            'description' => 'Administrator role'
        ];
        
        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$roleData]);
            
        // Call the method
        $result = $this->roleRepository->getRoleByUUID('11111111-1111-1111-1111-111111111111');
        
        // Assert result
        $this->assertEquals($roleData, $result);
    }
    
    /**
     * Test getting role name
     */
    public function testGetRoleName(): void
    {
        // Sample role name
        $roleName = 'admin';
        
        // Configure query builder to return test data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([['name' => $roleName]]);
            
        // Call the method
        $result = $this->roleRepository->getRoleName('11111111-1111-1111-1111-111111111111');
        
        // Assert result
        $this->assertEquals($roleName, $result);
    }
    
    /**
     * Test getting user roles
     */
    public function testGetUserRoles(): void
    {
        // Sample user roles
        $userRoles = [
            [
                'role_uuid' => '11111111-1111-1111-1111-111111111111',
                'user_uuid' => 'user-uuid',
                'role_name' => 'admin',
                'description' => 'Administrator role'
            ],
            [
                'role_uuid' => '22222222-2222-2222-2222-222222222222',
                'user_uuid' => 'user-uuid',
                'role_name' => 'editor',
                'description' => 'Editor role'
            ]
        ];
        
        // Configure query builder to return test data
        $this->mockQueryBuilder->method('join')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn($userRoles);
            
        // Call the method
        $result = $this->roleRepository->getUserRoles('user-uuid');
        
        // Assert result
        $this->assertEquals($userRoles, $result);
    }
    
    /**
     * Test assigning role to user
     */
    public function testAssignRole(): void
    {
        // Configure query builder for successful insert
        $this->mockQueryBuilder->method('insert')
            ->willReturn(1); // Return 1 row inserted
            
        // Call the method
        $result = $this->roleRepository->assignRole('user-uuid', 'role-uuid');
        
        // Assert result
        $this->assertTrue($result);
    }
    
    /**
     * Test removing role from user
     */
    public function testUnassignRole(): void
    {
        // Configure query builder for successful delete
        $this->mockQueryBuilder->method('delete')
            ->willReturn(true); // Return true for successful deletion
            
        // Call the method
        $result = $this->roleRepository->unassignRole('user-uuid', 'role-uuid');
        
        // Assert result
        $this->assertTrue($result);
    }
    
    /**
     * Test checking if user has role
     */
    public function testUserHasRole(): void
    {
        // Configure query builder for both calls
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('join')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('count')
            ->willReturnOnConsecutiveCalls(1, 0);
            
        // Test user has role
        $result1 = $this->roleRepository->userHasRole('user-uuid', 'admin');
        $this->assertTrue($result1);
        
        // Test user doesn't have role
        $result2 = $this->roleRepository->userHasRole('user-uuid', 'super-admin');
        $this->assertFalse($result2);
    }
}
