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
        // Set default test environment settings
        $_ENV['EVENTS_ENABLED'] = $_ENV['EVENTS_ENABLED'] ?? 'false';
        $_ENV['CACHE_DRIVER'] = $_ENV['CACHE_DRIVER'] ?? 'file';
        $_ENV['DB_POOLING_ENABLED'] = $_ENV['DB_POOLING_ENABLED'] ?? 'false';

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
    protected function createRequest(
        string $method,
        string $uri,
        array $parameters = []
    ): \Symfony\Component\HttpFoundation\Request {
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

    /**
     * Set static mock methods for a class
     *
     * @param string $className The class name to mock
     * @param array $methods The methods to mock and their return values
     * @return void
     */
    protected function setStaticMockMethods(string $className, array $methods): void
    {
        foreach ($methods as $methodName => $returnValue) {
            $this->setStaticMethodMock($className, $methodName, $returnValue);
        }
    }

    /**
     * Set a static method to return a specific value
     *
     * @param string $className The class name
     * @param string $methodName The method name
     * @param mixed $returnValue The value to return
     * @return void
     */
    protected function setStaticMethodMock(string $className, string $methodName, $returnValue): void
    {
        // For RateLimiter class, we have a special case implementation
        if ($className === \Glueful\Security\RateLimiter::class) {
            $this->mockRateLimiterStaticMethod($methodName, $returnValue);
            return;
        }

        // For other classes, implement a generic approach if needed
        throw new \RuntimeException(
            "Static method mocking for class {$className} not implemented. " .
            "Add specific implementation in TestCase::setStaticMethodMock."
        );
    }

    /**
     * Mock a static method in the RateLimiter class
     *
     * @param string $methodName The method to mock
     * @param mixed $returnValue The value to return
     * @return void
     */
    private function mockRateLimiterStaticMethod(string $methodName, $returnValue): void
    {
        // Store the mock in a static property for test access
        $mockProperty = "__mock_{$methodName}";
        $this->setPrivateStaticProperty(\Glueful\Security\RateLimiter::class, $mockProperty, $returnValue);
    }
}
