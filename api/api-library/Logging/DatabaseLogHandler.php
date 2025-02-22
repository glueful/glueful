<?php
declare(strict_types=1);

namespace Glueful\Api\Library\Logging;


use PDO;
use Glueful\Api\Schemas\SchemaManagerFactory;
use Monolog\LogRecord;
use Monolog\Level;
use Monolog\Handler\AbstractProcessingHandler;
use Glueful\Api\Library\Utils;
use RuntimeException;

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
 * @package Glueful\Api\Library\Logging
 */
class DatabaseLogHandler extends AbstractProcessingHandler 
{
    /** @var PDO Database connection instance */
    private PDO $db;

    /**
     * Initialize database log handler
     *
     * Sets up the handler with specified log level and establishes
     * database connection through SchemaManagerFactory.
     *
     * @param int $level Minimum logging level (defaults to DEBUG)
     */
    public function __construct(Level $level = Level::Debug) 
    {
        parent::__construct($level);
        
        // Get database connection directly using SchemaManagerFactory
        $this->db = SchemaManagerFactory::getConnection();
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
        $stmt = $this->db->prepare(
            "INSERT INTO app_logs (uuid, channel, level, message, context, exec_time, created_at)
             VALUES (:uuid, :channel, :level, :message, :context, :exec_time, :created_at)"
        );
        
        $stmt->execute([
            'uuid'       => Utils::generateNanoID(12),  // Generate unique identifier
            'channel'    => $record->channel,           // Logging channel (e.g., 'api', 'app')
            'level'      => $record->level->value,      // Log level (INFO, WARNING, ERROR)
            'message'    => $record->message,           // Main log message
            'context'    => json_encode(                // Additional context data
                $record->context, 
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'exec_time'  => $record->context['exec_time'] ?? null,  // Optional execution timing
            'created_at' => $record->datetime->format('Y-m-d H:i:s'),  // Timestamp
        ]);
    }
}