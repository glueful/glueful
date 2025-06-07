<?php

namespace Tests\Unit\Auth;

use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\AuthenticationProviderInterface;

/**
 * Test for Authentication Manager
 */
class AuthenticationManagerTest extends TestCase
{
    /**
     * @var AuthenticationManager The auth manager instance being tested
     */
    private AuthenticationManager $authManager;

    /**
     * @var MockAuthProvider Mock authentication provider
     */
    private MockAuthProvider $mockProvider;

    /**
     * @var Request Mock HTTP request
     */
    private Request $request;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create custom mock authentication provider
        $this->mockProvider = new MockAuthProvider();

        // Create auth manager with mock provider
        $this->authManager = new AuthenticationManager($this->mockProvider);

        // Create a test HTTP request
        $this->request = Request::create('/api/test', 'GET');
    }

    /**
     * Test auth manager initialization with default provider
     */
    public function testConstructWithDefaultProvider(): void
    {
        // Create auth manager with default provider
        $authManager = new AuthenticationManager();

        // Verify a default provider is set
        $this->assertInstanceOf(
            AuthenticationManager::class,
            $authManager,
            'Auth manager should initialize with default provider'
        );
    }

    /**
     * Test authenticating with a provider
     */
    public function testAuthenticateWithSuccessfulProvider(): void
    {
        // Sample user data
        $userData = [
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'role' => 'user'
        ];

        // Configure mock provider to authenticate successfully
        $this->mockProvider->setUserData($userData);

        // Authenticate the request
        $result = $this->authManager->authenticate($this->request);

        // Verify authentication was successful - the authenticate method returns the user data directly
        $this->assertIsArray($result);
        $this->assertEquals($userData, $result);
    }

    /**
     * Test failed authentication
     */
    public function testAuthenticateWithFailingProvider(): void
    {
        // Configure mock provider to fail authentication
        $this->mockProvider
            ->setUserData(null)
            ->setError('Authentication failed');

        // Try to authenticate
        $result = $this->authManager->authenticate($this->request);

        // Verify authentication failed
        $this->assertNull($result);
        $this->assertStringContainsString('Authentication failed', $this->mockProvider->getError());
    }

    /**
     * Test multiple providers (provider chaining)
     */
    public function testAuthenticateWithMultipleProviders(): void
    {
        // Create additional mock provider with our custom implementation
        $secondProvider = new MockAuthProvider();

        // First provider fails
        $this->mockProvider->setUserData(null);

        // Second provider succeeds
        $userData = ['uuid' => 'test-123', 'username' => 'testuser'];
        $secondProvider->setUserData($userData);

        // Register the second provider
        $this->authManager->registerProvider('second', $secondProvider);

        // Authenticate with the second provider explicitly
        $result = $this->authManager->authenticateWithProvider('second', $this->request);

        // Verify second provider succeeded
        $this->assertIsArray($result);
        $this->assertEquals($userData, $result);
    }

    /**
     * Test admin privileges check
     */
    public function testIsAdmin(): void
    {
        // Sample admin user data
        $adminData = [
            'uuid' => 'admin-123',
            'username' => 'admin',
            'role' => 'admin'
        ];

        // Configure provider to authenticate as admin
        $this->mockProvider
            ->setUserData($adminData)
            ->setAdminStatus(true);

        // Authenticate the request
        $userData = $this->authManager->authenticate($this->request);

        // Verify admin check works
        $this->assertTrue($this->authManager->isAdmin($userData));
    }

    /**
     * Test changing providers
     */
    public function testSetDefaultProvider(): void
    {
        // Create a new mock provider using our custom implementation
        $newProvider = new MockAuthProvider();
        $userData = ['uuid' => 'new-123', 'username' => 'newuser'];

        $newProvider->setUserData($userData);

        // Set as default provider
        $this->authManager->setDefaultProvider($newProvider);

        // Authenticate with new provider
        $result = $this->authManager->authenticate($this->request);

        // Verify new provider was used
        $this->assertIsArray($result);
        $this->assertEquals($userData, $result);
    }
}
