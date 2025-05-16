<?php

namespace Tests\Unit\Logging\Mocks;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Mock DatabaseLogHandler for testing without requiring a real database connection
 */
class MockDatabaseLogHandler extends AbstractProcessingHandler
{
    /** @var array Stored log records that would have been written to database */
    private array $storedLogRecords = [];

    /** @var array Handler configuration options */
    private array $options = [];

    /** @var string Table name where logs would be stored */
    private string $tableName = 'logs';

    /**
     * Constructor
     *
     * @param array $options Configuration options
     */
    public function __construct(array $options = [])
    {
        $level = $options['min_level'] ?? Level::Debug;
        $bubble = $options['bubble'] ?? true;

        parent::__construct($level, $bubble);

        $this->options = $options;

        // Set table name if provided
        if (isset($options['table'])) {
            $this->tableName = $options['table'];
        }
    }

    /**
     * Override write method to avoid actual database operations
     *
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        $this->storedLogRecords[] = $record;
    }

    /**
     * Override handle method to ensure proper implementation
     *
     * @param LogRecord $record
     * @return bool
     */
    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $processedRecord = $this->processRecord($record);
        if ($processedRecord !== null) {
            $this->write($processedRecord);
        }

        return false === $this->bubble;
    }

    /**
     * Set the table name
     *
     * @param string $tableName
     * @return self
     */
    public function setTable(string $tableName): self
    {
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * Get stored log records
     *
     * @return array
     */
    public function getStoredLogRecords(): array
    {
        return $this->storedLogRecords;
    }

    /**
     * Get configuration options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Process a log record
     *
     * @param LogRecord $record
     * @return void
     */
    protected function processRecord(LogRecord $record): LogRecord
    {
        // Make sure we actually call parent processing
        return parent::processRecord($record);
    }

    /**
     * Get table name
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get minimum log level
     *
     * @return Level
     */
    public function getMinimumLevel(): Level
    {
        return $this->level;
    }
}
