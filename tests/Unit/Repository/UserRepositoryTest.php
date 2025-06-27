<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use Tests\Unit\Repository\Mocks\TestUserRepository;
use Tests\Unit\Repository\Mocks\MockUserConnection;
use Tests\Helpers\DatabaseMock;
use Tests\TestCase;
use Glueful\DTOs\UsernameDTO;
use Glueful\DTOs\EmailDTO;
use PHPUnit\Framework\MockObject\MockObject;
use Glueful\Database\QueryBuilder;
use Glueful\Validation\Validator;

/**
 * User Repository Test
 *
 * Tests for the UserRepository class functionality including:
 * - User retrieval by various identifiers (ID, username, email)
 * - User profile data management
 * - Role association verification
 * - Password handling and validation
 */
class UserRepositoryTest extends TestCase
{
    /** @var TestUserRepository */
    private TestUserRepository $userRepository;

    /** @var QueryBuilder&MockObject */
    private $mockQueryBuilder;

    /** @var Validator&MockObject */
    private $mockValidator;

    /**
     * Setup before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects using our helper
        $this->mockQueryBuilder = DatabaseMock::createMockQueryBuilder($this);
        $this->mockValidator = $this->createMock(Validator::class);

        // Create repository with our mock objects
        $this->userRepository = new TestUserRepository($this->mockQueryBuilder, $this->mockValidator);
    }

    /**
     * Test finding a user by username
     */
    public function testFindByUsername(): void
    {
        // Sample user data
        $userData = [
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => '$2y$10$somehashedpassword',
            'status' => 'active',
            'created_at' => '2023-01-01 00:00:00'
        ];

        // Configure DTO validation to succeed
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder to return test data
        $mockStatement = $this->createMock(\PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn($userData);

        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$userData]);

        // Call the method
        $result = $this->userRepository->findByUsername('testuser');

        // Assert result matches expected data
        $this->assertEquals($userData, $result);
    }

    /**
     * Test finding a user by email
     */
    public function testFindByEmail(): void
    {
        // Sample user data
        $userData = [
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => '$2y$10$somehashedpassword',
            'status' => 'active',
            'created_at' => '2023-01-01 00:00:00'
        ];

        // Configure DTO validation to succeed
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder to return test data
        $mockStatement = $this->createMock(\PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn($userData);

        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$userData]);

        // Call the method
        $result = $this->userRepository->findByEmail('test@example.com');

        // Assert result matches expected data
        $this->assertEquals($userData, $result);
    }

    /**
     * Test finding a user by UUID
     */
    public function testFindByUUID(): void
    {
        // Sample user data
        $userData = [
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => '$2y$10$somehashedpassword',
            'status' => 'active',
            'created_at' => '2023-01-01 00:00:00'
        ];

        // Configure query builder to return test data
        $mockStatement = $this->createMock(\PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn($userData);

        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$userData]);

        // Call the method
        $result = $this->userRepository->findByUUID('12345678-1234-1234-1234-123456789012');

        // Assert result matches expected data
        $this->assertEquals($userData, $result);
    }

    /**
     * Test finding by username - existing user
     */
    public function testFindByUsernameExisting(): void
    {
        // Sample user data
        $userData = [
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'username' => 'existinguser',
            'email' => 'existing@example.com',
            'password' => '$2y$10$somehashedpassword',
            'status' => 'active',
            'created_at' => '2023-01-01 00:00:00'
        ];

        // Configure DTO validation to succeed
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder to return test data
        $mockStatement = $this->createMock(\PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn($userData);

        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$userData]);

        // Call the method - should find the user
        $result = $this->userRepository->findByUsername('existinguser');
        $this->assertNotNull($result);
        $this->assertEquals($userData, $result);
    }

    /**
     * Test finding by username - non-existent user
     */
    public function testFindByUsernameNotExisting(): void
    {
        // Configure DTO validation to succeed
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder to return empty data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([]);

        // Call the method - should not find any user
        $result = $this->userRepository->findByUsername('nonexistentuser');
        $this->assertNull($result);
    }

    /**
     * Test finding by email - existing user
     */
    public function testFindByEmailExisting(): void
    {
        // Sample user data
        $userData = [
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'username' => 'existinguser',
            'email' => 'existing@example.com',
            'password' => '$2y$10$somehashedpassword',
            'status' => 'active',
            'created_at' => '2023-01-01 00:00:00'
        ];

        // Configure DTO validation to succeed
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder to return test data
        $mockStatement = $this->createMock(\PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn($userData);

        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([$userData]);

        // Call the method - should find the user
        $result = $this->userRepository->findByEmail('existing@example.com');
        $this->assertNotNull($result);
        $this->assertEquals($userData, $result);
    }

    /**
     * Test finding by email - non-existent user
     */
    public function testFindByEmailNotExisting(): void
    {
        // Configure DTO validation to succeed
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder to return empty data
        $this->mockQueryBuilder->method('select')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('where')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('limit')
            ->willReturnSelf();
        $this->mockQueryBuilder->method('get')
            ->willReturn([]);

        // Call the method - should not find any user
        $result = $this->userRepository->findByEmail('nonexistent@example.com');
        $this->assertNull($result);
    }

    /**
     * Test user creation
     */
    public function testCreate(): void
    {
        $userData = [
            'username' => 'newuser',
            'email' => 'new@example.com',
            'password' => 'securepassword',
            'status' => 'active'
        ];

        // Configure validation to pass
        $this->mockValidator->method('validate')
            ->willReturn(true);

        // Configure query builder for successful insert
        $this->mockQueryBuilder->method('insert')
            ->willReturn(1);

        $result = $this->userRepository->create($userData);

        // Assert UUID was returned (can't test exact value due to random generation)
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }
}
