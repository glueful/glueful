<?php
namespace Tests\Unit\Mocks;

trait MocksStatic
{
    /**
     * Stores original static methods that have been mocked
     */
    private static $originalStatics = [];

    /**
     * Mock a static method in a class
     *
     * @param string $class The class name
     * @param string $method The method name
     * @param callable $mockImplementation The mock implementation
     */
    protected function mockStaticMethod(string $class, string $method, callable $mockImplementation): void
    {
        // Store the original method if not already stored
        $key = "{$class}::{$method}";
        if (!isset(self::$originalStatics[$key])) {
            // This isn't a perfect way to capture the original, but it's reasonable for tests
            self::$originalStatics[$key] = [$class, $method];
        }

        $this->registerMockFunction($class, $method, $mockImplementation);
    }

    /**
     * Register a mock function for a static method
     *
     * @param string $class The class name
     * @param string $method The method name
     * @param callable $mockImplementation The mock implementation
     */
    private function registerMockFunction(string $class, string $method, callable $mockImplementation): void
    {
        // Use a global variable to store mock implementations
        $GLOBALS['MOCK_STATIC_IMPLEMENTATIONS']["{$class}::{$method}"] = $mockImplementation;
    }

    /**
     * Get the mock implementation for a method if it exists
     *
     * @param string $class The class name
     * @param string $method The method name
     * @param array $args The arguments passed to the method
     * @return mixed|null The mock result or null if no mock exists
     */
    public static function getMockStaticImplementation(string $class, string $method, array $args)
    {
        $key = "{$class}::{$method}";
        if (isset($GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][$key])) {
            return call_user_func_array($GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][$key], $args);
        }
        return null;
    }

    /**
     * Reset all static mocks
     */
    protected function resetStaticMocks(): void
    {
        $GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'] = [];
    }
}
