<?php
declare(strict_types=1);

namespace Glueful\Logging;

use Monolog\LogRecord;
use Monolog\Level;
use Monolog\Handler\AbstractProcessingHandler;
use Glueful\Helpers\Utils;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;


/**
 * Database Log Handler
 * 
 * Handles writing log records to a database table. Extends Monolog's AbstractProcessingHandler
 * to provide database logging capabilities. Logs are stored in the 'app_logs' table with:
 * - Unique identifiers
 * - Log levels
 * - Timestamps
 * - Message content
 * - Contextual data
 * - Execution timing
 * - Channel information
 *
 * @package Glueful\Logging
 */
class DatabaseLogHandler extends AbstractProcessingHandler 
{
    private SchemaManager $schema;
    private QueryBuilder $db;
    protected string $table = 'app_logs';

    /**
     * Initialize database log handler
     *
     * Sets up the handler with specified log level and establishes
     * database connection through SchemaManagerFactory.
     *
     * @param int $level Minimum logging level (defaults to DEBUG)
     */
    public function __construct(array $options = []) 
    {
        parent::__construct($options['level'] ?? Level::Debug);
        $connection = new Connection();
        $this->schema = $connection->getSchemaManager();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        
        // Ensure logs table exists
        if (isset($options['table'])) {
            $this->table = $options['table'];
        }
        $this->ensureLogsTable();
        
        
    }


    /**
     * Set the database table name for storing log entries
     * 
     * @param string $table Table name
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }
    

    /**
     * Write log record to database
     *
     * Processes the log record and stores it in the app_logs table:
     * - Generates unique UUID for the log entry
     * - Formats datetime to MySQL compatible format
     * - Serializes context data to JSON
     * - Extracts execution time if available
     * - Handles potential database errors
     *
     * @param LogRecord $record Log record to write
     * @throws \PDOException If database write fails
     */
    protected function write(LogRecord $record): void 
    {
        try {
            // Insert log entry using SchemaManager
            $this->db->insert('app_logs', [
                'uuid' => Utils::generateNanoID(),
                'channel' => $record->channel,
                'level' => $record->level,
                'message' => $record->message,
                'context' => json_encode(
                    $record->context,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                'exec_time' => $record->context['exec_time'] ?? null,
                'created_at' => $record->datetime->format('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            error_log("Failed to write log to database: " . $e->getMessage());
        }
    }

    private function ensureLogsTable(): void
    {
        $this->schema->createTable('app_logs', [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'channel' => 'VARCHAR(50) NOT NULL',
            'level' => 'INTEGER NOT NULL',
            'message' => 'TEXT NOT NULL',
            'context' => 'JSON',
            'exec_time' => 'FLOAT NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ], [
            ['type' => 'INDEX', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'channel'],
            ['type' => 'INDEX', 'column' => 'level'],
            ['type' => 'INDEX', 'column' => 'created_at']
        ]);
    }
}