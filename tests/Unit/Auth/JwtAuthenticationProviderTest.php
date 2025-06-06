<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;
use Tests\Helpers\AuditLoggerMock;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Auth\JWTService;
use Glueful\Cache\CacheEngine;

/**
 * Tests for the JWT Authentication Provider
 */
class JwtAuthenticationProviderTest extends TestCase
{
    /**
     * @var JwtAuthenticationProvider The provider being tested
     */
    private JwtAuthenticationProvider $provider;

    /**
     * @var array Sample user data for test tokens
     */
    private array $testUserData;

    /**
     * @var MockObject Mock of the AuditLogger
     */
    private $mockAuditLogger;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up JWT Service
        $_ENV['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';
        $_SERVER['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';

        // Set up SQLite in-memory database for testing
        $_ENV['DB_ENGINE'] = 'sqlite';
        $_ENV['DB_SQLITE_DATABASE'] = ':memory:';

        // Mock the AuditLogger to prevent database connections
        $this->mockAuditLogger = AuditLoggerMock::setup($this);

        // Configure mock audit logger methods
        $this->mockAuditLogger->method('audit')->willReturn('mock-audit-id-' . uniqid());
        $this->mockAuditLogger->method('authEvent')->willReturn('mock-auth-id-' . uniqid());
        $this->mockAuditLogger->method('dataEvent')->willReturn('mock-data-id-' . uniqid());
        $this->mockAuditLogger->method('configEvent')->willReturn('mock-config-id-' . uniqid());

        // Create provider
        $this->provider = new JwtAuthenticationProvider();

        // Sample user data
        $this->testUserData = [
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'role' => 'user'
        ];

        // Reset the cache for a clean test state
        CacheEngine::reset();

        // Create required database tables for TokenStorageService
        $this->createDatabaseTables();
    }

    /**
     * Create required database tables for testing
     */
    private function createDatabaseTables(): void
    {
        // Get a database connection to create tables
        $connection = new \Glueful\Database\Connection();
        $pdo = $connection->getPDO();
        // Create auth_sessions table
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            user_uuid TEXT NOT NULL,
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            access_expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            provider TEXT DEFAULT 'jwt',
            user_agent TEXT,
            ip_address TEXT,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_token_refresh DATETIME DEFAULT CURRENT_TIMESTAMP,
            token_fingerprint TEXT NOT NULL
        )");
    }

    /**
     * Setup token session in cache
     */
    private function setupTokenSession(string $token, array $userData): string
    {
        $sessionId = 'test-session-' . uniqid();

        // Create session data that matches what TokenStorageService expects
        $sessionData = [
            'uuid' => $sessionId,
            'user_uuid' => $userData['uuid'],
            'access_token' => $token,
            'refresh_token' => 'test-refresh-token-' . uniqid(),
            'access_expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'refresh_expires_at' => date('Y-m-d H:i:s', time() + 604800),
            'provider' => 'jwt',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'last_token_refresh' => date('Y-m-d H:i:s'),
            'token_fingerprint' => hash('sha256', $token)
        ];

        // Merge in the user data
        $sessionData = array_merge($sessionData, $userData);

        // Create cache entry that TokenStorageService expects
        CacheEngine::set('session_token:' . $token, json_encode($sessionData));

        return $sessionId;
    }

    /**
     * Test authentication with valid token
     */
    public function testAuthenticateWithValidToken(): void
    {
        // Create a valid JWT token
        $token = JWTService::generate($this->testUserData, 60);

        // Set up session for the token
        $this->setupTokenSession($token, $this->testUserData);

        // Create request with Authorization header
        $request = new Request();
        $request->headers->set('Authorization', "Bearer $token");

        // Authenticate the request
        $userData = $this->provider->authenticate($request);

        // Verify authentication succeeded
        $this->assertNotNull($userData);
        $this->assertEquals($this->testUserData['uuid'], $userData['uuid']);
        $this->assertEquals($this->testUserData['username'], $userData['username']);

        // Verify request attributes were set
        $this->assertTrue($request->attributes->get('authenticated'));
        $this->assertEquals($this->testUserData['uuid'], $request->attributes->get('user_id'));
        $this->assertArrayHasKey('uuid', $request->attributes->get('user_data'));
    }

    /**
     * Test authentication with missing token
     */
    public function testAuthenticateWithMissingToken(): void
    {
        // Create request without Authorization header
        $request = new Request();

        // Try to authenticate
        $userData = $this->provider->authenticate($request);

        // Verify authentication failed
        $this->assertNull($userData);
        $this->assertStringContainsString('No authentication token', $this->provider->getError());
    }

    /**
     * Test authentication with invalid token
     */
    public function testAuthenticateWithInvalidToken(): void
    {
        // Create request with invalid token
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer invalid.token.string');

        // Try to authenticate
        $userData = $this->provider->authenticate($request);

        // Verify authentication failed
        $this->assertNull($userData);
        $this->assertNotNull($this->provider->getError());
    }

    /**
     * Test authentication with expired token
     */
    public function testAuthenticateWithExpiredToken(): void
    {
        // Create a token that expires immediately
        $token = JWTService::generate($this->testUserData, 0);

        // Wait to ensure token expires
        sleep(1);

        // Create request with expired token
        $request = new Request();
        $request->headers->set('Authorization', "Bearer $token");

        // Try to authenticate
        $userData = $this->provider->authenticate($request);

        // Verify authentication failed
        $this->assertNull($userData);
        $this->assertStringContainsString('Invalid or expired', $this->provider->getError());
    }

    /**
     * Test admin privileges check
     */
    public function testIsAdmin(): void
    {
        // Regular user
        $userData = $this->testUserData;
        $this->assertFalse($this->provider->isAdmin($userData));

        // Admin user with role field - this won't work per implementation
        $adminData = $this->testUserData;
        $adminData['role'] = 'admin';
        $this->assertFalse($this->provider->isAdmin($adminData));

        // Admin user with is_admin flag
        $adminData = $this->testUserData;
        $adminData['is_admin'] = true;
        $this->assertTrue($this->provider->isAdmin($adminData));

        // Admin user with roles array containing superuser (no longer supported - use RBAC extension)
        $adminData = $this->testUserData;
        $adminData['roles'] = [['name' => 'superuser']];
        $this->assertFalse($this->provider->isAdmin($adminData)); // Role-based admin checking moved to RBAC extension

        // Admin user with roles but not superuser
        $adminData = $this->testUserData;
        $adminData['roles'] = [['name' => 'editor']];
        $this->assertFalse($this->provider->isAdmin($adminData));

        // Test with nested user structure
        $nestedData = ['user' => $this->testUserData];
        $this->assertFalse($this->provider->isAdmin($nestedData));

        $nestedData['user']['is_admin'] = true;
        $this->assertTrue($this->provider->isAdmin($nestedData));
    }

    /**
     * Test token extraction from different request formats
     */
    public function testTokenExtractionFromDifferentFormats(): void
    {
        $token = JWTService::generate($this->testUserData, 60);

        // Set up session for the token
        $this->setupTokenSession($token, $this->testUserData);

        // Test Authorization header format
        $request = new Request();
        $request->headers->set('Authorization', "Bearer $token");
        $userData = $this->provider->authenticate($request);
        $this->assertNotNull($userData);

        // Reset cache and set up session again for a clean test
        CacheEngine::reset();
        $this->setupTokenSession($token, $this->testUserData);

        // Test token in cookie - this will fail because current implementation doesn't check cookies
        $request = new Request();
        $request->cookies->set('token', $token);
        $userData = $this->provider->authenticate($request);
        $this->assertNull($userData, "Token from cookies should not be processed by current implementation");
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset the AuditLogger singleton
        AuditLoggerMock::reset();
    }
}
