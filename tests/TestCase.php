<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base TestCase for Glueful
 * 
 * Provides common functionality for all tests in the Glueful framework.
 * Extend this class instead of PHPUnit's TestCase directly.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Setup test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Load environment variables for testing
        $this->loadTestEnvironment();
        
        // Reset any static properties that might persist between tests
        $this->resetStaticProperties();
    }
    
    /**
     * Load environment variables for testing
     */
    protected function loadTestEnvironment(): void
    {
        // Load environment variables from .env.testing if it exists
        if (file_exists(__DIR__ . '/../.env.testing')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.testing');
            $dotenv->load();
        }
    }
    
    /**
     * Reset static properties that might persist between tests
     */
    protected function resetStaticProperties(): void
    {
        // Reset any static properties here
        // Example: \Glueful\Http\Router::reset();
    }
    
    /**
     * Create a mock of a class with specific methods mocked
     */
    protected function createMock(string $className): \PHPUnit\Framework\MockObject\MockObject
    {
        return parent::createMock($className);
    }
    
    /**
     * Helper method to create a test request
     */
    protected function createRequest(string $method, string $uri, array $parameters = []): \Symfony\Component\HttpFoundation\Request
    {
        $request = \Symfony\Component\HttpFoundation\Request::create($uri, $method, $parameters);
        return $request;
    }
}
