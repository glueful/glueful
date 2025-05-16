<?php

namespace Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Logging\Mocks\MockLogManager;
use Tests\Unit\Logging\Mocks\MockLogSanitizer;

/**
 * Test for LogManager sanitization functionality using mock classes
 */
class LogSanitizationMockTest extends TestCase
{
    private MockLogManager $logger;

    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new MockLogManager();
    }

    /**
     * Test context sanitization removes sensitive data
     */
    public function testSanitizeSensitiveData(): void
    {
        // Create test context with sensitive data
        $context = [
            'username' => 'testuser',
            'password' => 'secret123',
            'api_key' => 'ak_live_1234567890abcdef',
            'credit_card' => '4111-1111-1111-1111',
            'auth_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            'social_security' => '123-45-6789',
            'email' => 'test@example.com',
            'nested' => [
                'password' => 'nested_secret',
                'token' => 'nested_token',
                'safe' => 'safe_value'
            ]
        ];

        // Sanitize the context using our mock
        $sanitized = $this->logger->sanitizeContext($context);

        // Check that sensitive fields were redacted
        $this->assertEquals('testuser', $sanitized['username']); // Should keep username
        $this->assertEquals('[REDACTED]', $sanitized['password']); // Should mask password
        $this->assertEquals('[REDACTED]', $sanitized['api_key']); // Should mask API key
        $this->assertStringContainsString('XXXX', $sanitized['credit_card']); // Should mask credit card
        $this->assertEquals('[REDACTED]', $sanitized['auth_token']); // Should mask auth token
        $this->assertEquals('[REDACTED]', $sanitized['social_security']); // Should mask SSN
        $this->assertEquals('test@example.com', $sanitized['email']); // Should keep email

        // Check nested values
        $this->assertEquals('[REDACTED]', $sanitized['nested']['password']); // Should mask nested password
        $this->assertEquals('[REDACTED]', $sanitized['nested']['token']); // Should mask nested token
        $this->assertEquals('safe_value', $sanitized['nested']['safe']); // Should keep safe value
    }

    /**
     * Test that JSON data inside strings is also sanitized
     */
    public function testSanitizeJsonStrings(): void
    {
        // Create test context with JSON string containing sensitive data
        $jsonString = json_encode([
            'user' => 'testuser',
            'password' => 'secret_in_json',
            'token' => 'json_token_123'
        ]);

        $context = [
            'json_data' => $jsonString,
            'normal_field' => 'normal_value'
        ];

        // Sanitize the context
        $sanitized = $this->logger->sanitizeContext($context);

        // Check that the JSON string was sanitized
        $this->assertNotEquals($jsonString, $sanitized['json_data']); // Should be different from original

        // Decode the sanitized JSON for easier checking
        $sanitizedJson = json_decode($sanitized['json_data'], true);
        $this->assertEquals('testuser', $sanitizedJson['user']); // Should keep user
        $this->assertEquals('[REDACTED]', $sanitizedJson['password']); // Should mask password
        $this->assertEquals('[REDACTED]', $sanitizedJson['token']); // Should mask token
    }

    /**
     * Test context enrichment adds standard fields
     */
    public function testEnrichContext(): void
    {
        // Create test environment
        $_SERVER['REQUEST_URI'] = '/test/endpoint';

        // Enrich a context
        $enriched = $this->logger->enrichContext([]);

        // Check that standard fields were added
        $this->assertArrayHasKey('request_uri', $enriched);
        $this->assertEquals('/test/endpoint', $enriched['request_uri']);

        // Memory usage should be present
        $this->assertArrayHasKey('memory_usage', $enriched);

        // Hostname should be present
        $this->assertArrayHasKey('hostname', $enriched);
    }
}
