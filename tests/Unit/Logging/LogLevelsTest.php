<?php

namespace Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Glueful\Logging\LogManager;
use Monolog\Level;
use ReflectionClass;

/**
 * Test for LogManager log level functionality
 */
class LogLevelsTest extends TestCase
{
    /** @var string Temporary directory for test logs */
    private string $tempLogDir;

    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset LogManager singleton instance before each test
        LogManager::resetInstance();

        // Create temporary directory for log files
        $this->tempLogDir = sys_get_temp_dir() . '/glueful_test_logs_' . uniqid() . '/';
        mkdir($this->tempLogDir, 0755, true);
    }

    /**
     * Clean up tests
     */
    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDirectory($this->tempLogDir);

        // Reset LogManager singleton instance after each test
        LogManager::resetInstance();

        parent::tearDown();
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Test that shouldLog method correctly filters log levels
     */
    public function testShouldLogFilter(): void
    {
        $logger = new LogManager('', 30, 'app');

        // Set minimum level to warning
        $logger->setMinimumLevel(Level::Warning);

        // Use reflection to access private method
        $reflection = new ReflectionClass($logger);
        $shouldLogMethod = $reflection->getMethod('shouldLog');
        $shouldLogMethod->setAccessible(true);

        // Test level filtering - these should pass
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Warning));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Error));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Critical));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Alert));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Emergency));

        // These should be filtered out
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Notice));
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Info));
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Debug));

        // Test with string levels
        $this->assertTrue($shouldLogMethod->invoke($logger, 'warning'));
        $this->assertTrue($shouldLogMethod->invoke($logger, 'error'));
        $this->assertFalse($shouldLogMethod->invoke($logger, 'info'));

        // Test with numeric levels
        $this->assertTrue($shouldLogMethod->invoke($logger, 400)); // Warning
        $this->assertTrue($shouldLogMethod->invoke($logger, 500)); // Error
        $this->assertFalse($shouldLogMethod->invoke($logger, 200)); // Info
    }

    /**
     * Test log method with different levels
     */
    public function testLogWithVariousLevels(): void
    {
        // Create a real logger
        $logger = new LogManager('', 30, 'app');

        // Verify that the logger has the expected methods
        $this->assertTrue(method_exists($logger, 'emergency'), 'LogManager should have emergency method');
        $this->assertTrue(method_exists($logger, 'alert'), 'LogManager should have alert method');
        $this->assertTrue(method_exists($logger, 'critical'), 'LogManager should have critical method');
        $this->assertTrue(method_exists($logger, 'error'), 'LogManager should have error method');
        $this->assertTrue(method_exists($logger, 'warning'), 'LogManager should have warning method');
        $this->assertTrue(method_exists($logger, 'notice'), 'LogManager should have notice method');
        $this->assertTrue(method_exists($logger, 'info'), 'LogManager should have info method');
        $this->assertTrue(method_exists($logger, 'debug'), 'LogManager should have debug method');
    }

    /**
     * Test that log messages below minimum level are filtered
     */
    public function testLogLevelFiltering(): void
    {
        // Create a logger with minimum level set to Warning
        $logger = new LogManager('', 30, 'app');
        $logger->setMinimumLevel(Level::Warning);

        // Access the shouldLog method to verify filtering
        $reflection = new ReflectionClass($logger);
        $shouldLogMethod = $reflection->getMethod('shouldLog');
        $shouldLogMethod->setAccessible(true);

        // Test which levels should be logged and which filtered
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Emergency));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Alert));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Critical));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Error));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Warning));

        // These should be filtered (below warning)
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Notice));
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Info));
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Debug));
    }

    /**
     * Test changing log level during runtime
     */
    public function testChangingLogLevel(): void
    {
        $logger = new LogManager('', 30, 'app');

        // Start with Error level
        $logger->setMinimumLevel(Level::Error);

        $reflection = new ReflectionClass($logger);
        $minimumLevelProperty = $reflection->getProperty('minimumLevel');
        $minimumLevelProperty->setAccessible(true);
        $shouldLogMethod = $reflection->getMethod('shouldLog');
        $shouldLogMethod->setAccessible(true);

        // Verify initial level
        $this->assertEquals(Level::Error, $minimumLevelProperty->getValue($logger));
        $this->assertFalse($shouldLogMethod->invoke($logger, Level::Warning)); // Below minimum

        // Change to Debug level
        $logger->setMinimumLevel(Level::Debug);

        // Verify new level
        $this->assertEquals(Level::Debug, $minimumLevelProperty->getValue($logger));
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Warning)); // Now passes
        $this->assertTrue($shouldLogMethod->invoke($logger, Level::Debug)); // Debug now passes
    }

    /**
     * Test setMinimumLevelByName method
     */
    public function testSetMinimumLevelByName(): void
    {
        $logger = new LogManager('', 30, 'app');

        // Test setting by various names
        $reflection = new ReflectionClass($logger);
        $minimumLevelProperty = $reflection->getProperty('minimumLevel');
        $minimumLevelProperty->setAccessible(true);

        $logger->setMinimumLevelByName('debug');
        $this->assertEquals(Level::Debug, $minimumLevelProperty->getValue($logger));

        $logger->setMinimumLevelByName('INFO');  // Test case insensitivity
        $this->assertEquals(Level::Info, $minimumLevelProperty->getValue($logger));

        $logger->setMinimumLevelByName('Warning');
        $this->assertEquals(Level::Warning, $minimumLevelProperty->getValue($logger));

        $logger->setMinimumLevelByName('ERROR');
        $this->assertEquals(Level::Error, $minimumLevelProperty->getValue($logger));

        $logger->setMinimumLevelByName('critical');
        $this->assertEquals(Level::Critical, $minimumLevelProperty->getValue($logger));

        $logger->setMinimumLevelByName('ALERT');
        $this->assertEquals(Level::Alert, $minimumLevelProperty->getValue($logger));

        $logger->setMinimumLevelByName('emergency');
        $this->assertEquals(Level::Emergency, $minimumLevelProperty->getValue($logger));
    }

    /**
     * Test handling of invalid level names
     */
    public function testInvalidLevelName(): void
    {
        $logger = new LogManager('', 30, 'app');

        // Set a known level first
        $logger->setMinimumLevelByName('warning');

        $reflection = new ReflectionClass($logger);
        $minimumLevelProperty = $reflection->getProperty('minimumLevel');
        $minimumLevelProperty->setAccessible(true);
        $warningLevel = $minimumLevelProperty->getValue($logger);

        // Test with invalid name (should throw exception)
        $this->expectException(\InvalidArgumentException::class);
        $logger->setMinimumLevelByName('invalid_level_name');
    }
}
