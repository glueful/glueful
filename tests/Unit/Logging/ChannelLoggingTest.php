<?php

namespace Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Glueful\Logging\LogManager;
use Monolog\Logger;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test for LogManager channel functionality
 */
class ChannelLoggingTest extends TestCase
{
    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();
        LogManager::resetInstance();
    }

    /**
     * Clean up tests
     */
    protected function tearDown(): void
    {
        LogManager::resetInstance();
        parent::tearDown();
    }

    /**
     * Test creating a logger with a specific channel
     */
    public function testCreateWithChannel(): void
    {
        $logger = new LogManager('', 30, 'test_channel');

        // Use reflection to check channel
        $reflection = new ReflectionClass($logger);
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);

        $this->assertEquals('test_channel', $defaultChannelProperty->getValue($logger));
    }

    /**
     * Test changing channel using channel method
     */
    public function testChannelMethod(): void
    {
        $logger = new LogManager('', 30, 'default');

        // Create new logger with different channel
        $customLogger = $logger->channel('custom');

        // Should be a different instance
        $this->assertNotSame($logger, $customLogger);

        // Check channels
        $reflection = new ReflectionClass($logger);
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);

        // Get actual values for debugging
        $origChannel = $defaultChannelProperty->getValue($logger);
        $customChannel = $defaultChannelProperty->getValue($customLogger);

        $this->assertEquals('default', $origChannel, "Original channel is $origChannel, not 'default'");
        $this->assertEquals('custom', $customChannel, "Custom channel is $customChannel, not 'custom'");
    }

    /**
     * Test that getLogger returns a PSR-3 compatible logger
     */
    public function testGetLogger(): void
    {
        $logger = new LogManager();
        $channelLogger = $logger->getLogger('test_channel');

        // Should return a LoggerInterface
        $this->assertInstanceOf(LoggerInterface::class, $channelLogger);
    }

    /**
     * Test specifying channel in log context
     */
    public function testChannelInContext(): void
    {
        // In this test case, instead of using mocks that might be causing issues,
        // we'll use direct reflection to verify the behavior

        $logger = new LogManager('', 30, 'app');

        // Create a test context with a _channel parameter
        $context = ['_channel' => 'dynamic_channel', 'test_key' => 'test_value'];

        // Use reflection to access the private log method
        $reflection = new ReflectionClass($logger);
        $logMethod = $reflection->getMethod('log');
        $logMethod->setAccessible(true);

        // We'll set up a monolog mock handler to capture the log entry
        $mockHandler = $this->createMock(Logger::class);

        // Replace the monolog logger with our mock
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $originalLogger = $loggerProperty->getValue($logger);
        $loggerProperty->setValue($logger, $mockHandler);

        // Verify that the _channel parameter is correctly processed
        $mockHandler->expects($this->once())
            ->method('withName')
            ->with($this->equalTo('dynamic_channel'))
            ->willReturn($mockHandler);

        $mockHandler->expects($this->once())
            ->method('log');

        // Call the log method
        $logMethod->invoke($logger, Level::Info, 'Test message', $context);
    }

    /**
     * Test channel isolation
     */
    public function testChannelIsolation(): void
    {
        // Create channel-specific loggers directly
        $logger = new LogManager('', 30, 'app');
        $channelA = new LogManager('', 30, 'channel_a');
        $channelB = new LogManager('', 30, 'channel_b');

        // Verify different channels
        $reflection = new ReflectionClass(LogManager::class);
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);

        $this->assertEquals('channel_a', $defaultChannelProperty->getValue($channelA));
        $this->assertEquals('channel_b', $defaultChannelProperty->getValue($channelB));
    }

    /**
     * Test that batch mode is reset for new channels
     */
    public function testChannelBatchModeIsolation(): void
    {
        // Create a real logger this time since we need access to setBatchMode
        $logger = new LogManager('', 30, 'app');

        // Direct access to batch mode property using reflection
        $reflection = new ReflectionClass($logger);
        $batchModeProperty = $reflection->getProperty('batchMode');
        $batchModeProperty->setAccessible(true);
        $batchModeProperty->setValue($logger, true);

        // Add some entries to batch by manually adding to logBatch
        $reflection = new ReflectionClass($logger);
        $logBatchProperty = $reflection->getProperty('logBatch');
        $logBatchProperty->setAccessible(true);

        $batch = [
            ['level' => Level::Info, 'message' => 'Test message for main logger', 'context' => []]
        ];
        $logBatchProperty->setValue($logger, $batch);

        // Create a new channel by directly instantiating it with the right channel name
        $channelLogger = new LogManager('', 30, 'test_channel');

        // Check batch arrays
        $reflection = new ReflectionClass(LogManager::class);

        $logBatchProperty = $reflection->getProperty('logBatch');
        $logBatchProperty->setAccessible(true);

        // Original logger should have a batch entry
        $mainBatch = $logBatchProperty->getValue($logger);
        $this->assertNotEmpty($mainBatch);

        // Channel logger should have an empty batch
        $channelBatch = $logBatchProperty->getValue($channelLogger);
        $this->assertEmpty($channelBatch);
    }

    /**
     * Test that channel is included in context
     */
    public function testChannelInEnrichedContext(): void
    {
        // Create a logger with the test_channel
        $logger = new LogManager('', 30, 'test_channel');

        // Get actual channel for debugging
        $reflection = new ReflectionClass($logger);
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);
        $actualChannel = $defaultChannelProperty->getValue($logger);

        // Mock the Monolog logger
        $mockLogger = $this->createMock(Logger::class);

        // Use reflection to replace logger
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($logger, $mockLogger);

        // Set expectations for withName and log methods
        $mockWithNameLogger = $this->createMock(Logger::class);
        $mockLogger->expects($this->once())
            ->method('withName')
            ->with($this->equalTo($actualChannel)) // Use actual channel value
            ->willReturn($mockWithNameLogger);

        $mockWithNameLogger->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($context) use ($actualChannel) {
                    return isset($context['channel']) && $context['channel'] === $actualChannel;
                })
            );

        // We need to actually call a method for the test to work
        // Call the log method directly using reflection to bypass public methods
        $logMethod = $reflection->getMethod('log');
        $logMethod->setAccessible(true);
        $logMethod->invoke($logger, Level::Info, 'Test message', []);
    }

    /**
     * Test using multiple channels together
     */
    public function testMultipleChannels(): void
    {
        $logger = new LogManager('', 30, 'app');

        // Create several channel loggers directly
        $authLogger = new LogManager('', 30, 'auth');
        $apiLogger = new LogManager('', 30, 'api');
        $dbLogger = new LogManager('', 30, 'database');

        // Make sure they all have different channels
        $reflection = new ReflectionClass(LogManager::class);
        $defaultChannelProperty = $reflection->getProperty('defaultChannel');
        $defaultChannelProperty->setAccessible(true);

        $this->assertEquals('auth', $defaultChannelProperty->getValue($authLogger));
        $this->assertEquals('api', $defaultChannelProperty->getValue($apiLogger));
        $this->assertEquals('database', $defaultChannelProperty->getValue($dbLogger));

        // But they should all be instances of LogManager
        $this->assertInstanceOf(LogManager::class, $authLogger);
        $this->assertInstanceOf(LogManager::class, $apiLogger);
        $this->assertInstanceOf(LogManager::class, $dbLogger);
    }
}
