<?php
namespace Tests\Unit\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;
use Glueful\Auth\JwtAuthenticationProvider;
use Glueful\Auth\JWTService;
use Glueful\Auth\TokenManager;
use Glueful\Auth\SessionCacheManager;
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
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up JWT Service
        $_ENV['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';
        $_SERVER['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';
        
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
    }
    
    /**
     * Setup token session in cache
     */
    private function setupTokenSession(string $token, array $userData): string
    {
        $sessionId = 'test-session-' . uniqid();
        
        // Create token->session mapping in cache
        CacheEngine::set('token:' . $token, $sessionId);
        
        // Create session data in cache
        CacheEngine::set('session:' . $sessionId, $userData);
        
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
        
        // Admin user with roles array containing superuser
        $adminData = $this->testUserData;
        $adminData['roles'] = [['name' => 'superuser']];
        $this->assertTrue($this->provider->isAdmin($adminData));
        
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
}
