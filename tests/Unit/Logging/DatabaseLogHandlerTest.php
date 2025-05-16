<?php

namespace Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use Glueful\Logging\DatabaseLogHandler;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\QueryBuilder;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Unit\Logging\Mocks\MockDatabaseLogHandler;

/**
 * Test for DatabaseLogHandler functionality
 */
class DatabaseLogHandlerTest extends TestCase
{
    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test initialization of DatabaseLogHandler
     */
    public function testInitialization(): void
    {
        // Create mock handler
        $handler = new MockDatabaseLogHandler();

        // Default level should be DEBUG (100)
        $this->assertEquals(Level::Debug, $handler->getLevel());
    }

    /**
     * Test that table name can be set correctly
     */
    public function testTableCreation(): void
    {
        // Create mock handler with custom table name
        $handler = new MockDatabaseLogHandler(['table' => 'custom_logs_table']);

        // Verify table name was set correctly
        $this->assertEquals('custom_logs_table', $handler->getTableName());

        // No need to test table creation as our mock doesn't actually create tables
    }

    /**
     * Test writing a log record to the database
     */
    public function testWriteLogRecord(): void
    {
        // Create mock handler
        $handler = new MockDatabaseLogHandler(['table' => 'app_logs']);

        // Create a log record
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test_channel',
            Level::Info,
            'Test message',
            [],
            []
        );

        // Process the record - note: handle returns false by default when bubble is true
        $handler->handle($record);

        // Verify the record was stored regardless of return value
        $storedRecords = $handler->getStoredLogRecords();
        $this->assertCount(1, $storedRecords);
        $this->assertEquals('test_channel', $storedRecords[0]->channel);
        $this->assertEquals(Level::Info, $storedRecords[0]->level);
        $this->assertEquals('Test message', $storedRecords[0]->message);
    }

    /**
     * Test setting table name
     */
    public function testSetTable(): void
    {
        // Create mock handler
        $handler = new MockDatabaseLogHandler();

        // Set custom table name
        $handler->setTable('custom_logs');

        // Verify the table name was set correctly
        $this->assertEquals('custom_logs', $handler->getTableName());
    }

    /**
     * Test setting minimum log level
     */
    public function testSetLevel(): void
    {
        // Create mock handler
        $handler = new MockDatabaseLogHandler();

        // Set custom level
        $handler->setLevel(Level::Warning);

        // Check level
        $this->assertEquals(Level::Warning, $handler->getLevel());
    }

    /**
     * Test that records below minimum level are not processed
     */
    public function testLevelFiltering(): void
    {
        // Create handler with WARNING level
        $handler = new MockDatabaseLogHandler(['min_level' => Level::Warning]);

        // Create an INFO level record (below WARNING)
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test_channel',
            Level::Info,
            'This should be filtered',
            [],
            []
        );

        // This should not process the record (return false)
        $this->assertFalse($handler->handle($record));

        // Verify no records were stored
        $this->assertCount(0, $handler->getStoredLogRecords());
    }

    /**
     * Test exception handling behavior - our mock doesn't throw exceptions,
     * but we can test the normal flow works correctly
     */
    public function testErrorHandling(): void
    {
        // Create handler
        $handler = new MockDatabaseLogHandler();

        // Create a log record
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test_channel',
            Level::Info,
            'Test message',
            [],
            []
        );

        // Process the record - should work without exceptions
        $handler->handle($record);

        // Verify record was stored successfully
        $this->assertCount(1, $handler->getStoredLogRecords());
    }

    /**
     * Test logging with context data
     */
    public function testContextLogging(): void
    {
        // Create mock handler
        $handler = new MockDatabaseLogHandler();

        // Create a log record with context
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test_channel',
            Level::Info,
            'Test message',
            ['user_id' => 123, 'action' => 'login'],
            []
        );

        // Process the record
        $handler->handle($record);

        // Verify the record was stored with context
        $storedRecords = $handler->getStoredLogRecords();
        $this->assertCount(1, $storedRecords);
        $this->assertEquals('test_channel', $storedRecords[0]->channel);
        $this->assertArrayHasKey('user_id', $storedRecords[0]->context);
        $this->assertEquals(123, $storedRecords[0]->context['user_id']);
        $this->assertEquals('login', $storedRecords[0]->context['action']);
    }
}
