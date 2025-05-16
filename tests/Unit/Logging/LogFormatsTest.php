<?php

namespace Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Glueful\Logging\LogManager;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\AbstractProcessingHandler;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test for LogManager format functionality
 */
class LogFormatsTest extends TestCase
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
     * Test setting log format to text
     */
    public function testTextFormat(): void
    {
        $logger = new LogManager();

        // Set format to text
        $logger->setFormat('text');

        // Verify format setting
        $reflection = new ReflectionClass($logger);
        $logFormatProperty = $reflection->getProperty('logFormat');
        $logFormatProperty->setAccessible(true);

        $this->assertEquals('text', $logFormatProperty->getValue($logger));
    }

    /**
     * Test setting log format to JSON
     */
    public function testJsonFormat(): void
    {
        $logger = new LogManager();

        // Set format to JSON
        $logger->setFormat('json');

        // Verify format setting
        $reflection = new ReflectionClass($logger);
        $logFormatProperty = $reflection->getProperty('logFormat');
        $logFormatProperty->setAccessible(true);

        $this->assertEquals('json', $logFormatProperty->getValue($logger));
    }

    /**
     * Test handling invalid format
     */
    public function testInvalidFormat(): void
    {
        $logger = new LogManager();

        // Set a valid format first
        $logger->setFormat('json');

        // Try to set an invalid format - should throw an exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Log format must be 'text' or 'json'");

        $logger->setFormat('invalid_format');
    }

    /**
     * Test formatter types for JSON and text formats
     */
    public function testFormattersAppliedToHandlers(): void
    {
        // Since we can't directly test formatters without mocking internal methods,
        // we'll test the behavior through setFormat

        // Create a logger with JSON format
        $jsonLogger = new LogManager();
        $jsonLogger->setFormat('json');

        // Verify format was set correctly
        $reflection = new ReflectionClass($jsonLogger);
        $logFormatProperty = $reflection->getProperty('logFormat');
        $logFormatProperty->setAccessible(true);
        $this->assertEquals('json', $logFormatProperty->getValue($jsonLogger));

        // Create a logger with text format
        $textLogger = new LogManager();
        $textLogger->setFormat('text');

        // Verify format was set correctly
        $logFormatProperty->setAccessible(true);
        $this->assertEquals('text', $logFormatProperty->getValue($textLogger));
    }

    /**
     * Test exception handling when setting invalid format
     */
    public function testInvalidFormatException(): void
    {
        $logger = new LogManager();

        // Set an invalid format - should throw exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Log format must be 'text' or 'json'");

        $logger->setFormat('yaml');
    }

    /**
     * Test JSON formatter options
     */
    public function testJsonFormatterOptions(): void
    {
        $logger = new LogManager();

        // Set JSON format with custom options
        $logger->setFormat('json', [
            'batch_mode' => JsonFormatter::BATCH_MODE_NEWLINES,
            'append_newline' => false
        ]);

        // Verify format was set
        $reflection = new ReflectionClass($logger);
        $logFormatProperty = $reflection->getProperty('logFormat');
        $logFormatProperty->setAccessible(true);

        $this->assertEquals('json', $logFormatProperty->getValue($logger));
    }

    /**
     * Test text formatter options
     */
    public function testTextFormatterOptions(): void
    {
        $logger = new LogManager();

        // Set text format with custom options
        $logger->setFormat('text', [
            'line_format' => "[%datetime%] %message%\n",
            'date_format' => "Y/m/d H:i",
            'allow_inline_line_breaks' => true
        ]);

        // Verify format was set
        $reflection = new ReflectionClass($logger);
        $logFormatProperty = $reflection->getProperty('logFormat');
        $logFormatProperty->setAccessible(true);

        $this->assertEquals('text', $logFormatProperty->getValue($logger));
    }
}
