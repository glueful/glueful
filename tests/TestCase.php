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
    /** @var bool Whether mock autoloader has been initialized */
    private static bool $mockAutoloaderInitialized = false;

    /**
     * Setup test environment before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize mock autoloader if not already done
        $this->initializeMockAutoloader();
        
        // Load environment variables for testing
        $this->loadTestEnvironment();
        
        // Reset any static properties that might persist between tests
        $this->resetStaticProperties();
    }
    
    /**
     * Initialize mock autoloader
     */
    private function initializeMockAutoloader(): void
    {
        if (!self::$mockAutoloaderInitialized) {
            $mockAutoloaderPath = __DIR__ . '/Unit/Auth/MockAutoloader.php';
            if (file_exists($mockAutoloaderPath)) {
                require_once $mockAutoloaderPath;
                self::$mockAutoloaderInitialized = true;
            }
        }
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
    
    /**
     * Set a private static property on a class using reflection
     * 
     * @param string $className The name of the class
     * @param string $propertyName The name of the static property
     * @param mixed $value The value to set
     * @return void
     */
    protected function setPrivateStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($className);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
