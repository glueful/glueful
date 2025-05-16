<?php
namespace Tests\Unit\Auth;

use Tests\TestCase;
use Glueful\Auth\JWTService;

/**
 * Tests for the JWT Service
 */
class JWTServiceTest extends TestCase
{
    /**
     * @var array Test payload for JWT tokens
     */
    private array $testPayload;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set environment variable for JWT key
        $_ENV['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';
        $_SERVER['JWT_KEY'] = 'test-jwt-secret-key-for-unit-tests';

        // Define a test payload for tokens
        $this->testPayload = [
            'uid' => '123e4567-e89b-12d3-a456-426614174000',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'role' => 'user'
        ];
    }

    /**
     * Test token generation
     */
    public function testGenerateCreatesValidToken(): void
    {
        // Generate a token with a 60-second expiration
        $token = JWTService::generate($this->testPayload, 60);

        // Assert the token is a non-empty string
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Verify the token has the expected structure (header.payload.signature)
        $this->assertEquals(2, substr_count($token, '.'), 'JWT token should have 2 dots separating 3 segments');

        // Split the token into parts
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT token should have 3 parts');

        // Validate we can decode the header and payload parts
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // Verify header contents
        $this->assertIsArray($header);
        $this->assertEquals('HS256', $header['alg']);
        $this->assertEquals('JWT', $header['typ']);

        // Verify payload contents
        $this->assertIsArray($payload);
        $this->assertEquals($this->testPayload['uid'], $payload['uid']);
        $this->assertEquals($this->testPayload['username'], $payload['username']);
        $this->assertEquals($this->testPayload['role'], $payload['role']);
        $this->assertArrayHasKey('exp', $payload, 'Payload should contain expiration time');
    }

    /**
     * Test token validation
     */
    public function testValidateVerifiesCorrectToken(): void
    {
        // Generate a token
        $token = JWTService::generate($this->testPayload, 60);

        // Validate the token
        $payload = JWTService::decode($token);

        // Check if validation passed
        $this->assertIsArray($payload);
        $this->assertEquals($this->testPayload['uid'], $payload['uid']);
        $this->assertEquals($this->testPayload['username'], $payload['username']);
        $this->assertEquals($this->testPayload['role'], $payload['role']);
    }

    /**
     * Test expired token validation
     */
    public function testValidateRejectsExpiredToken(): void
    {
        // Generate a token that expires immediately (0 seconds)
        $token = JWTService::generate($this->testPayload, 0);

        // Wait a second to ensure it's expired
        sleep(1);

        // Validate the token - should fail
        $payload = JWTService::decode($token);

        // Check if validation failed
        $this->assertNull($payload, 'Expired token should not validate');
    }

    /**
     * Test invalid token validation
     */
    public function testValidateRejectsInvalidToken(): void
    {
        // Generate a token
        $token = JWTService::generate($this->testPayload, 60);

        // Tamper with the token
        $tamperedToken = substr($token, 0, -5) . 'XXXXX';

        // Validate the tampered token
        $payload = JWTService::decode($tamperedToken);

        // Check if validation failed
        $this->assertNull($payload, 'Tampered token should not validate');
    }

    /**
     * Test token payload extraction
     */
    public function testGetPayloadExtractsCorrectData(): void
    {
        // Generate a token
        $token = JWTService::generate($this->testPayload, 60);

        // Extract payload without validation
        $payload = JWTService::extractClaims($token);

        // Check if extraction worked correctly
        $this->assertIsArray($payload);
        $this->assertEquals($this->testPayload['uid'], $payload['uid']);
        $this->assertEquals($this->testPayload['username'], $payload['username']);
        $this->assertEquals($this->testPayload['email'], $payload['email']);
    }

    /**
     * Test token invalidation
     */
    public function testInvalidationPreventsTokenReuse(): void
    {
        // Generate a token
        $token = JWTService::generate($this->testPayload, 60);

        // First validation should pass
        $payload = JWTService::decode($token);
        $this->assertIsArray($payload);

        // Invalidate the token
        JWTService::invalidate($token);

        // Second validation should fail
        $payload = JWTService::decode($token);
        $this->assertNull($payload, 'Invalidated token should not validate');
    }
}
