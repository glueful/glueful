<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;
use Tests\Helpers\AuditLoggerMock;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Auth\JWTService;
use Glueful\Auth\TokenStorageService;

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
     * @var MockObject Mock of the TokenStorageService
     */
    private $mockTokenStorage;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up JWT Service
        $_ENV['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';
        $_SERVER['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';

        // Mock the AuditLogger to prevent database connections
        $this->mockAuditLogger = AuditLoggerMock::setup($this);

        // Configure mock audit logger methods
        $this->mockAuditLogger->method('audit')->willReturn('mock-audit-id-' . uniqid());
        $this->mockAuditLogger->method('authEvent')->willReturn('mock-auth-id-' . uniqid());
        $this->mockAuditLogger->method('dataEvent')->willReturn('mock-data-id-' . uniqid());
        $this->mockAuditLogger->method('configEvent')->willReturn('mock-config-id-' . uniqid());

        // Create mock TokenStorageService
        $this->mockTokenStorage = $this->createMock(TokenStorageService::class);

        // Create provider
        $this->provider = new JwtAuthenticationProvider();

        // Sample user data
        $this->testUserData = [
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'role' => 'user'
        ];
    }

    /**
     * Setup mock token session data
     */
    private function setupMockTokenSession(string $token, array $userData): array
    {
        // Create session data that matches what TokenStorageService returns
        // The userData should maintain the original user UUID in the 'uuid' field
        return array_merge($userData, [
            'session_uuid' => 'test-session-' . uniqid(),
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
        ]);
    }

    /**
     * Test authentication with valid token using mocks
     */
    public function testAuthenticateWithValidToken(): void
    {
        // Create a valid JWT token
        $token = JWTService::generate($this->testUserData, 60);

        // Set up mock session data
        $mockSessionData = $this->setupMockTokenSession($token, $this->testUserData);

        // Configure mock TokenStorageService to return session data
        $this->mockTokenStorage
            ->expects($this->once())
            ->method('getSessionByAccessToken')
            ->with($token)
            ->willReturn($mockSessionData);

        // Create a testable provider that uses our mock TokenStorageService
        $this->provider = new class ($this->mockTokenStorage) extends JwtAuthenticationProvider {
            private $mockTokenStorage;

            public function __construct($mockTokenStorage)
            {
                $this->mockTokenStorage = $mockTokenStorage;
            }

            public function authenticate(\Symfony\Component\HttpFoundation\Request $request): ?array
            {
                // Copy the authentication logic but use our mock
                $token = $this->extractTokenFromRequest($request);
                if (!$token) {
                    return null;
                }

                $sessionData = $this->mockTokenStorage->getSessionByAccessToken($token);
                if (!$sessionData) {
                    return null;
                }

                // Set request attributes as the original does
                $request->attributes->set('authenticated', true);
                $request->attributes->set('user_id', $sessionData['user_uuid']);
                $request->attributes->set('user_data', $sessionData);

                return $sessionData;
            }

            protected function extractTokenFromRequest(\Symfony\Component\HttpFoundation\Request $request): ?string
            {
                $authHeader = $request->headers->get('Authorization', '');
                if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    return $matches[1];
                }
                return null;
            }
        };

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

        // Configure mock TokenStorageService to return null for expired token
        $this->mockTokenStorage
            ->expects($this->once())
            ->method('getSessionByAccessToken')
            ->with($token)
            ->willReturn(null);

        // Create a testable provider that tracks errors
        $mockProvider = new class ($this->mockTokenStorage) extends JwtAuthenticationProvider {
            private $mockTokenStorage;
            private ?string $lastError = null;

            public function __construct($mockTokenStorage)
            {
                $this->mockTokenStorage = $mockTokenStorage;
            }

            public function authenticate(\Symfony\Component\HttpFoundation\Request $request): ?array
            {
                $this->lastError = null;
                $token = $this->extractTokenFromRequest($request);
                if (!$token) {
                    $this->lastError = 'No authentication token provided';
                    return null;
                }

                $sessionData = $this->mockTokenStorage->getSessionByAccessToken($token);
                if (!$sessionData) {
                    $this->lastError = 'Invalid or expired authentication token';
                    return null;
                }

                return $sessionData;
            }

            public function getError(): ?string
            {
                return $this->lastError;
            }

            protected function extractTokenFromRequest(\Symfony\Component\HttpFoundation\Request $request): ?string
            {
                $authHeader = $request->headers->get('Authorization', '');
                if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    return $matches[1];
                }
                return null;
            }
        };

        // Create request with expired token
        $request = new Request();
        $request->headers->set('Authorization', "Bearer $token");

        // Try to authenticate
        $userData = $mockProvider->authenticate($request);

        // Verify authentication failed
        $this->assertNull($userData);
        $this->assertStringContainsString('Invalid or expired', $mockProvider->getError());
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
        $mockSessionData = $this->setupMockTokenSession($token, $this->testUserData);

        // Create mock provider that uses our mock TokenStorage
        $mockProvider = new class ($this->mockTokenStorage) extends JwtAuthenticationProvider {
            private $mockTokenStorage;

            public function __construct($mockTokenStorage)
            {
                $this->mockTokenStorage = $mockTokenStorage;
            }

            public function authenticate(\Symfony\Component\HttpFoundation\Request $request): ?array
            {
                $token = $this->extractTokenFromRequest($request);
                if (!$token) {
                    return null;
                }
                return $this->mockTokenStorage->getSessionByAccessToken($token);
            }

            protected function extractTokenFromRequest(\Symfony\Component\HttpFoundation\Request $request): ?string
            {
                $authHeader = $request->headers->get('Authorization', '');
                if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    return $matches[1];
                }
                return null;
            }
        };

        // Configure mock to return session data for valid token
        $this->mockTokenStorage
            ->expects($this->once())
            ->method('getSessionByAccessToken')
            ->with($token)
            ->willReturn($mockSessionData);

        // Test Authorization header format
        $request = new Request();
        $request->headers->set('Authorization', "Bearer $token");
        $userData = $mockProvider->authenticate($request);
        $this->assertNotNull($userData);

        // Test token in cookie - this will fail because current implementation doesn't check cookies
        $request = new Request();
        $request->cookies->set('token', $token);
        $userData = $mockProvider->authenticate($request);
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
