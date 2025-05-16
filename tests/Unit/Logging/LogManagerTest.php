<?php

namespace Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Glueful\Logging\LogManager;
use Monolog\Level;
use Monolog\Logger;
use ReflectionClass;

/**
 * Test for LogManager functionality
 */
class LogManagerTest extends TestCase
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

        // Configure test environment
        $_SERVER['REQUEST_URI'] = '/test/endpoint';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
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
     * Test that LogManager singleton pattern works correctly
     */
    public function testSingletonPattern(): void
    {
        // First instance
        $logger1 = LogManager::getInstance();
        $this->assertInstanceOf(LogManager::class, $logger1);

        // Second instance should be the same object
        $logger2 = LogManager::getInstance();
        $this->assertSame($logger1, $logger2);

        // After reset, should be a new instance
        LogManager::resetInstance();
        $logger3 = LogManager::getInstance();
        $this->assertNotSame($logger1, $logger3);
    }

    /**
     * Test basic initialization of LogManager
     */
    public function testInitialization(): void
    {
        $logger = new LogManager('', 30, 'test_channel');

        // Use reflection to check private properties
        $reflection = new ReflectionClass($logger);

        // Check default channel
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);
        $this->assertEquals('test_channel', $defaultChannelProperty->getValue($logger));

        // Check that Monolog logger was created
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertInstanceOf(Logger::class, $loggerProperty->getValue($logger));
    }

    /**
     * Test configuration options
     */
    public function testConfiguration(): void
    {
        $logger = new LogManager('', 30, 'app');

        // Configure with options
        $logger->configure([
            'debug_mode' => true,
            'max_buffer_size' => 200,
            'default_channel' => 'custom_channel',
            'suppress_exceptions' => false
        ]);

        // Use reflection to check private properties
        $reflection = new ReflectionClass($logger);

        // Check debug mode
        $debugModeProperty = $reflection->getProperty('debugMode');
        $debugModeProperty->setAccessible(true);
        $this->assertTrue($debugModeProperty->getValue($logger));

        // Check buffer size
        $maxBufferSizeProperty = $reflection->getProperty('maxBufferSize');
        $maxBufferSizeProperty->setAccessible(true);
        $this->assertEquals(200, $maxBufferSizeProperty->getValue($logger));

        // Check default channel
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);
        $this->assertEquals('custom_channel', $defaultChannelProperty->getValue($logger));

        // Check suppress exceptions
        $suppressExceptionsProperty = $reflection->getProperty('suppressExceptions');
        $suppressExceptionsProperty->setAccessible(true);
        $this->assertFalse($suppressExceptionsProperty->getValue($logger));
    }

    /**
     * Test that LogManager implements PSR-3 LoggerInterface methods
     */
    public function testPsr3Implementation(): void
    {
        $logger = new LogManager();

        // Test that all PSR-3 methods are available
        $this->assertTrue(method_exists($logger, 'emergency'));
        $this->assertTrue(method_exists($logger, 'alert'));
        $this->assertTrue(method_exists($logger, 'critical'));
        $this->assertTrue(method_exists($logger, 'error'));
        $this->assertTrue(method_exists($logger, 'warning'));
        $this->assertTrue(method_exists($logger, 'notice'));
        $this->assertTrue(method_exists($logger, 'info'));
        $this->assertTrue(method_exists($logger, 'debug'));
        $this->assertTrue(method_exists($logger, 'log'));
    }

    /**
     * Test that LogManager implements LogManagerInterface
     */
    public function testLogManagerInterface(): void
    {
        $logger = new LogManager();

        // Test LogManagerInterface methods
        $this->assertTrue(method_exists($logger, 'getLogger'));
        $this->assertTrue(method_exists($logger, 'error'));
        $this->assertTrue(method_exists(LogManager::class, 'getInstance'));
    }

    /**
     * Test log formatting methods
     */
    public function testLogFormatConfiguration(): void
    {
        $logger = new LogManager();

        // Test text format (default)
        $textLogger = $logger->setFormat('text');
        $this->assertSame($logger, $textLogger); // Should return self

        // Test JSON format
        $jsonLogger = $logger->setFormat('json');
        $this->assertSame($logger, $jsonLogger); // Should return self

        // Check that format was set
        $reflection = new ReflectionClass($logger);
        $logFormatProperty = $reflection->getProperty('logFormat');
        $logFormatProperty->setAccessible(true);
        $this->assertEquals('json', $logFormatProperty->getValue($logger));
    }

    /**
     * Test batch mode configuration
     */
    public function testBatchMode(): void
    {
        $logger = new LogManager();

        // Enable batch mode
        $batchLogger = $logger->setBatchMode(true, 50);
        $this->assertSame($logger, $batchLogger); // Should return self

        // Check batch mode settings
        $reflection = new ReflectionClass($logger);

        $batchModeProperty = $reflection->getProperty('batchMode');
        $batchModeProperty->setAccessible(true);
        $this->assertTrue($batchModeProperty->getValue($logger));

        $maxBatchSizeProperty = $reflection->getProperty('maxBatchSize');
        $maxBatchSizeProperty->setAccessible(true);
        $this->assertEquals(50, $maxBatchSizeProperty->getValue($logger));

        // Check log batch
        $logBatchProperty = $reflection->getProperty('logBatch');
        $logBatchProperty->setAccessible(true);
        $this->assertIsArray($logBatchProperty->getValue($logger));
        $this->assertEmpty($logBatchProperty->getValue($logger));

        // Test disable batch mode
        $logger->setBatchMode(false);
        $this->assertFalse($batchModeProperty->getValue($logger));
    }

    /**
     * Test cleanup method
     */
    public function testCleanup(): void
    {
        $logger = new LogManager();

        // Use reflection to set some values
        $reflection = new ReflectionClass($logger);

        $recentLogsProperty = $reflection->getProperty('recentLogs');
        $recentLogsProperty->setAccessible(true);
        $recentLogsProperty->setValue($logger, [['test' => 'log']]);

        $timersProperty = $reflection->getProperty('timers');
        $timersProperty->setAccessible(true);
        $timersProperty->setValue($logger, ['test_timer' => ['start' => microtime(true)]]);

        $logBatchProperty = $reflection->getProperty('logBatch');
        $logBatchProperty->setAccessible(true);
        $logBatchProperty->setValue($logger, [['level' => 'debug', 'message' => 'test', 'context' => []]]);

        // Run cleanup
        $logger->cleanup();

        // Check that properties were cleared
        $this->assertEmpty($recentLogsProperty->getValue($logger));
        $this->assertEmpty($timersProperty->getValue($logger));
        $this->assertEmpty($logBatchProperty->getValue($logger));
    }

    /**
     * Test setting minimum log level
     */
    public function testMinimumLogLevel(): void
    {
        $logger = new LogManager();

        // Set by Level enum
        $logger->setMinimumLevel(Level::Warning);

        // Use reflection to check setting
        $reflection = new ReflectionClass($logger);
        $minimumLevelProperty = $reflection->getProperty('minimumLevel');
        $minimumLevelProperty->setAccessible(true);
        $this->assertEquals(Level::Warning, $minimumLevelProperty->getValue($logger));

        // Set by name
        $logger->setMinimumLevelByName('error');
        $this->assertEquals(Level::Error, $minimumLevelProperty->getValue($logger));
    }

    /**
     * Test that setting sampling rate works
     */
    public function testSamplingRate(): void
    {
        $logger = new LogManager();

        // Test valid rates
        $logger->setSamplingRate(0.5);

        $reflection = new ReflectionClass($logger);
        $samplingRateProperty = $reflection->getProperty('samplingRate');
        $samplingRateProperty->setAccessible(true);
        $this->assertEquals(0.5, $samplingRateProperty->getValue($logger));

        // Test invalid rates get capped
        $logger->setSamplingRate(-0.1); // Should be capped at 0
        $this->assertEquals(0, $samplingRateProperty->getValue($logger));

        $logger->setSamplingRate(1.5); // Should be capped at 1
        $this->assertEquals(1.0, $samplingRateProperty->getValue($logger));
    }
}
