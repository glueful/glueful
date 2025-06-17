<?php

declare(strict_types=1);

namespace Glueful\Logging;

use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Http\RequestUserContext;
use Psr\Log\LoggerInterface;

/**
 * Enterprise Audit Logger
 *
 * Extends the core logging system with enterprise-grade audit capabilities.
 * Provides tamper-evident, standardized audit logging with long-term retention
 * and compliance features.
 *
 * Features:
 * - Tamper-evident log storage with cryptographic protection
 * - Multiple storage backends (database, file, external services)
 * - Event correlation and tracking across requests/sessions
 * - Configurable retention policies for different audit event types
 * - Compliance-ready reporting for regulatory requirements
 * - Search and filtering capabilities
 *
 * @package Glueful\Logging
 */
class AuditLogger extends LogManager
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var string Database table for audit logs */
    protected string $auditTable = 'audit_logs';

    /** @var string Database table for tracked entities */
    protected string $entitiesTable = 'audit_entities';

    /** @var bool Flag indicating whether logging is active */
    private static bool $isLogging = false;

    /** @var array Audit log retention policies by category (days) */
    protected array $retentionPolicies = [
        AuditEvent::CATEGORY_AUTH => 365,     // Authentication events: 1 year
        AuditEvent::CATEGORY_AUTHZ => 365,    // Authorization events: 1 year
        AuditEvent::CATEGORY_DATA => 180,     // Data access events: 6 months
        AuditEvent::CATEGORY_ADMIN => 730,    // Admin actions: 2 years
        AuditEvent::CATEGORY_CONFIG => 730,   // Config changes: 2 years
        AuditEvent::CATEGORY_SYSTEM => 90,    // System events: 90 days
        AuditEvent::CATEGORY_USER => 365,     // User management: 1 year
        AuditEvent::CATEGORY_FILE => 90       // File operations: 90 days
    ];

    /** @var array High-volume categories that should be processed asynchronously */
    protected array $highVolumeCategories = [
        AuditEvent::CATEGORY_DATA,      // Data access events
        AuditEvent::CATEGORY_FILE,      // File operations
        AuditEvent::CATEGORY_SYSTEM,    // System events
        'resource_access',              // Resource access (if used)
        'api_access'                    // API access (if used)
    ];

    /** @var array Categories that should always be processed synchronously for compliance */
    protected array $criticalCategories = [
        AuditEvent::CATEGORY_AUTH,      // Authentication events
        AuditEvent::CATEGORY_AUTHZ,     // Authorization events
        AuditEvent::CATEGORY_ADMIN,     // Administrative actions
        AuditEvent::CATEGORY_CONFIG     // Configuration changes
    ];

    /** @var array Storage backends configuration */
    protected array $storageBackends = [
        'database' => true,
        'file' => true,
        'external' => false
    ];

    /** @var string External service endpoint for audit logs */
    protected ?string $externalServiceUrl = null;

    /** @var string External service API key */
    protected ?string $externalServiceApiKey = null;

    /** @var bool Enable immutable storage (append-only) */
    protected bool $immutableStorage = true;

    /** @var array Map of event severities to log levels */
    protected array $severityLevelMap = [
        AuditEvent::SEVERITY_INFO => Level::Info,
        AuditEvent::SEVERITY_WARNING => Level::Warning,
        AuditEvent::SEVERITY_ERROR => Level::Error,
        AuditEvent::SEVERITY_CRITICAL => Level::Critical,
        AuditEvent::SEVERITY_ALERT => Level::Alert,
        AuditEvent::SEVERITY_EMERGENCY => Level::Emergency
    ];

    /** @var QueryBuilder Database connection */
    protected QueryBuilder $db;

    /** @var SchemaManager Schema manager */
    protected SchemaManager $schema;

    /** @var array Batch audit event queue */
    protected static array $batchQueue = [];

    /** @var float Last batch flush time */
    protected static float $lastBatchFlush = 0;

    /**
     * Initialize the audit logger
     *
     * Sets up audit log storage and handlers on top of the core logging system.
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        // Call parent constructor for base logging setup
        parent::__construct(
            $config['log_file'] ?? "",
            $config['max_files'] ?? 365,
            $config['default_channel'] ?? 'audit'
        );

        // Initialize database connection
        $connection = new Connection();
        $this->schema = $connection->getSchemaManager();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Apply any provided configuration
        $this->configure($config);

        // Set up audit-specific log handlers
        $this->setupAuditHandlers();

        // Ensure audit tables exist
        $this->ensureAuditTablesExist();
    }

    /**
     * Configure audit logging options
     *
     * @param array $options Configuration options
     * @return self Fluent interface
     */
    public function configure(array $options = []): self
    {
        // Call parent configure method first to handle standard options
        parent::configure($options);
        // Configure audit-specific options
        // Configure storage backends
        if (isset($options['storage_backends'])) {
            $this->storageBackends = array_merge($this->storageBackends, $options['storage_backends']);
        }

        // Configure external service
        if (isset($options['external_service_url'])) {
            $this->externalServiceUrl = $options['external_service_url'];
        }

        if (isset($options['external_service_api_key'])) {
            $this->externalServiceApiKey = $options['external_service_api_key'];
        }

        // Configure immutable storage
        if (isset($options['immutable_storage'])) {
            $this->immutableStorage = (bool)$options['immutable_storage'];
        }

        // Configure retention policies
        if (isset($options['retention_policies'])) {
            $this->retentionPolicies = array_merge($this->retentionPolicies, $options['retention_policies']);
        }

        return $this;
    }

    /**
     * Set up audit-specific log handlers
     *
     * @return void
     */
    protected function setupAuditHandlers(): void
    {
        // Get log directory from config or use default
        $logDirectory = config('app.logging.log_file_path') ?: dirname(dirname(__DIR__)) . '/storage/logs/audit/';

        // Create audit logs directory if it doesn't exist
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true)) {
            throw new \RuntimeException("Failed to create audit logs directory: $logDirectory");
        }

        // Set up JSON formatter for audit logs
        $formatter = new JsonFormatter();

        // Create handler for audit logs
        $auditHandler = $this->createAuditRotatingHandler(
            $logDirectory . 'audit.log',
            Level::Debug,
            true, // Bubble up to other handlers
            $this->retentionPolicies[AuditEvent::CATEGORY_ADMIN] // Use admin retention as default
        );

        // Set formatter
        $auditHandler->setFormatter($formatter);

        // Get access to the logger and add handler
        $logger = $this->getInternalLogger();
        $logger->pushHandler($auditHandler);

        // Add category-specific handlers if needed
        foreach ($this->retentionPolicies as $category => $days) {
            // Skip if the category-specific logging is not enabled
            if (!config("app.logging.audit.{$category}_separate_file", false)) {
                continue;
            }

            $categoryHandler = $this->createAuditRotatingHandler(
                $logDirectory . "{$category}.log",
                Level::Debug,
                false, // Don't bubble up
                $days
            );

            $categoryHandler->setFormatter($formatter);
            $logger->pushHandler($categoryHandler);
        }
    }

    /**
     * Get access to the protected logger instance from the parent
     *
     * @return Logger The Monolog logger instance
     */
    protected function getInternalLogger(): Logger
    {
        // Use Reflection to access the private logger property of the parent class
        $reflection = new \ReflectionClass(parent::class);
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        return $loggerProperty->getValue($this);
    }

    /**
     * Get access to the protected maxFiles value from the parent
     *
     * @return int The maximum number of log files
     */
    protected function getMaxFiles(): int
    {
        // Use Reflection to access the private maxFiles property of the parent class
        $reflection = new \ReflectionClass(parent::class);
        $maxFilesProperty = $reflection->getProperty('maxFiles');
        $maxFilesProperty->setAccessible(true);
        return $maxFilesProperty->getValue($this);
    }

    /**
     * Create a RotatingFileHandler with audit-specific retention settings
     *
     * Extension of the parent method that allows specifying a retention
     * period in days that overrides the default maxFiles setting.
     *
     * @param string $filename Log file path
     * @param Level|int $level Minimum log level for this handler
     * @param bool $bubble Whether to bubble logs up to higher handlers
     * @param int $retentionDays Number of days to keep the logs
     * @return RotatingFileHandler The configured handler
     */
    protected function createAuditRotatingHandler(
        string $filename,
        $level = Level::Debug,
        bool $bubble = true,
        int $retentionDays = 0
    ): RotatingFileHandler {
        // Use specified retention days if provided, otherwise use parent's maxFiles
        $maxFiles = $retentionDays > 0 ? $retentionDays : $this->getMaxFiles();

        // Create the handler with the specific retention period
        $handler = new RotatingFileHandler(
            $filename,
            $maxFiles,
            $level,
            $bubble
        );

        // Set the filename format based on date format
        $handler->setFilenameFormat('{filename}-{date}', RotatingFileHandler::FILE_PER_DAY);

        return $handler;
    }
    /**
     * Ensure audit tables exist in the database
     *
     * @return void
     */
    protected function ensureAuditTablesExist(): void
    {
        if (!$this->storageBackends['database']) {
            return;
        }

        // Create audit_logs table if it doesn't exist
        if (!$this->schema->tableExists($this->auditTable)) {
            // Create the audit logs table with all columns using the fluent interface pattern
            $this->schema->createTable($this->auditTable, [
                'id' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
                'uuid' => 'CHAR(12) NOT NULL',
                'event_id' => 'VARCHAR(36) NOT NULL',
                'batch_uuid' => 'VARCHAR(36) NULL',
                'category' => 'VARCHAR(50) NOT NULL',
                'action' => 'VARCHAR(100) NOT NULL',
                'severity' => 'VARCHAR(20) NOT NULL',
                'actor_id' => 'VARCHAR(36) NULL',
                'target_id' => 'VARCHAR(36) NULL',
                'target_type' => 'VARCHAR(50) NULL',
                'timestamp' => 'DATETIME NOT NULL',
                'ip_address' => 'VARCHAR(45) NULL',
                'user_agent' => 'TEXT NULL',
                'request_uri' => 'TEXT NULL',
                'request_method' => 'VARCHAR(10) NULL',
                'details' => 'JSON NULL',
                'related_event_id' => 'VARCHAR(36) NULL',
                'session_id' => 'VARCHAR(255) NULL',
                'integrity_hash' => 'VARCHAR(64) NOT NULL',
                'level' => 'INT NOT NULL DEFAULT 3', // Default level for audit events
                'immutable' => 'BOOLEAN NOT NULL DEFAULT ' . ($this->immutableStorage ? 'true' : 'false'),
                'retention_date' => 'DATETIME NULL',
                'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
            ])->addIndex([
                ['type' => 'UNIQUE', 'column' => 'uuid'],
                ['type' => 'UNIQUE', 'column' => 'event_id'],
                ['type' => 'INDEX', 'column' => 'batch_uuid'],
                ['type' => 'INDEX', 'column' => 'category'],
                ['type' => 'INDEX', 'column' => 'action'],
                ['type' => 'INDEX', 'column' => 'severity'],
                ['type' => 'INDEX', 'column' => 'actor_id'],
                ['type' => 'INDEX', 'column' => 'target_id'],
                ['type' => 'INDEX', 'column' => 'target_type'],
                ['type' => 'INDEX', 'column' => 'timestamp'],
                ['type' => 'INDEX', 'column' => 'related_event_id'],
                ['type' => 'INDEX', 'column' => 'session_id'],
                ['type' => 'INDEX', 'column' => 'integrity_hash'],
                ['type' => 'INDEX', 'column' => 'level'],
                ['type' => 'INDEX', 'column' => 'retention_date']
            ]);
        }

        // Create audit_entities table if it doesn't exist
        if (!$this->schema->tableExists($this->entitiesTable)) {
            // Create the entities table with all columns using the fluent interface pattern
            $this->schema->createTable($this->entitiesTable, [
                'id' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
                'uuid' => 'CHAR(12) NOT NULL',
                'entity_id' => 'VARCHAR(36) NOT NULL',
                'entity_type' => 'VARCHAR(50) NOT NULL',
                'entity_name' => 'VARCHAR(255) NOT NULL',
                'entity_metadata' => 'JSON NULL',
                'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
            ])->addIndex([
                ['type' => 'UNIQUE', 'column' => 'uuid'],
                ['type' => 'UNIQUE', 'column' => 'entity_id'],
                ['type' => 'INDEX', 'column' => 'entity_type'],
                ['type' => 'INDEX', 'column' => 'entity_name']
            ]);
        }
    }

    /**
     * Log an audit event
     *
     * @param AuditEvent $event Audit event to log
     * @return string Returns the event ID
     */
    public function logAuditEvent(AuditEvent $event): string
    {
        // Use a local static variable to track recursion within this specific call
        static $localIsLogging = false;

        // Prevent deep recursive logging but allow initial entry
        if ($localIsLogging) {
            return $event->getEventId();
        }

        // Check if event meets minimum level requirement
        $minimumLevel = (int) config('logging.audit.minimum_level', AuditEvent::LEVEL_INFO);
        if ($event->getLevel() > $minimumLevel) {
            // Event doesn't meet minimum level, skip logging
            return $event->getEventId();
        }

        // Check if we should skip this request based on path or user agent
        if ($this->shouldSkipAuditLog($event)) {
            return $event->getEventId();
        }

        try {
            $localIsLogging = true;
            // Set the class-level flag but don't rely on it exclusively for recursion detection
            self::$isLogging = true;

            // Verify integrity of the event
            if (!$event->verifyIntegrity()) {
                throw new \RuntimeException("Audit event integrity check failed.");
            }

            // Store in database if enabled
            if ($this->storageBackends['database']) {
                // Check if this event should be processed asynchronously
                $asyncConfig = config('app.audit.async_processing', []);
                $isAsyncEnabled = $asyncConfig['enabled'] ?? false;
                $asyncCategories = $asyncConfig['categories'] ?? [];

                // Check if this event should be processed asynchronously
                if ($isAsyncEnabled && in_array($event->getCategory(), $asyncCategories)) {
                    // Queue for async processing
                    $this->queueAsyncAudit($event);
                } elseif (config('app.audit.batch_enabled', false)) {
                    // Use batch logging for immediate processing
                    $this->addToBatch($event);
                } else {
                    // Immediate database write
                    $this->storeAuditEventInDatabase($event);
                }
            }

            // Log through Monolog if file backend is enabled
            if ($this->storageBackends['file']) {
                $this->logToFile($event);
            }

            // Send to external service if configured
            if ($this->storageBackends['external'] && $this->externalServiceUrl) {
                $this->sendToExternalService($event);
            }
        } finally {
            self::$isLogging = false;
            $localIsLogging = false;
        }

        return $event->getEventId();
    }

    /**
     * Store audit event in the database
     *
     * @param AuditEvent $event Audit event to store
     * @return bool Success status
     */
    protected function storeAuditEventInDatabase(AuditEvent $event): bool
    {
        $eventData = $event->toArray();

        // Generate a unique UUID for the record
        $eventData['uuid'] = \Glueful\Helpers\Utils::generateNanoID();

        // Temporarily disable batch_uuid to avoid column errors
        // $eventData['batch_uuid'] = null;

        // Format timestamp to MySQL DATETIME format (from ISO 8601)
        if (isset($eventData['timestamp'])) {
            try {
                $dateTime = new \DateTime($eventData['timestamp']);
                $eventData['timestamp'] = $dateTime->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // If parsing fails, use current time as fallback
                $eventData['timestamp'] = date('Y-m-d H:i:s');
            }
        }

        // Calculate retention date based on category
        $retentionDays = $this->retentionPolicies[$event->getCategory()] ?? 365; // Default: 1 year
        $retentionDate = (new \DateTimeImmutable())->modify("+{$retentionDays} days")->format('Y-m-d H:i:s');

        // Add retention date to data
        $eventData['retention_date'] = $retentionDate;

        // JSON encode details field
        if (isset($eventData['details'])) {
            $eventData['details'] = json_encode($eventData['details'], JSON_UNESCAPED_SLASHES);
        }

        try {
            // Check if table exists
            $tableExists = $this->schema->tableExists($this->auditTable);

            if (!$tableExists) {
                $this->ensureAuditTablesExist();
                $tableExists = $this->schema->tableExists($this->auditTable);
            }

            // Insert the audit event
            $result = $this->db->insert($this->auditTable, $eventData);

            return $result !== false;
        } catch (\Exception $e) {
            // Log error but don't throw exception to avoid breaking application flow
            $errorMsg = "Failed to store audit event in database: " . $e->getMessage();
            parent::error($errorMsg, [
                'event_id' => $event->getEventId(),
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Log audit event to file using Monolog
     *
     * @param AuditEvent $event Audit event to log
     * @return void
     */
    protected function logToFile(AuditEvent $event): void
    {
        // Check for recursion
        if (self::$isLogging) {
            return;
        }

        try {
            self::$isLogging = true;

            // Map event severity to Monolog level
            $level = $this->severityLevelMap[$event->getSeverity()] ?? Level::Info;

            // Get event data for logging
            $eventData = $event->toArray();

            // Log to the channel matching the event category
            $this->getInternalLogger()
                ->withName($event->getCategory())
                ->log(
                    $level,
                    sprintf(
                        "AUDIT: [%s] %s",
                        $event->getAction(),
                        json_encode($eventData, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
                    ),
                    ['audit_event' => $eventData]
                );
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Send audit event to external logging service
     *
     * @param AuditEvent $event Audit event to send
     * @return bool Success status
     */
    protected function sendToExternalService(AuditEvent $event): bool
    {
        if (!$this->externalServiceUrl) {
            return false;
        }

        try {
            $client = new \GuzzleHttp\Client();

            $response = $client->post($this->externalServiceUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->externalServiceApiKey,
                    'X-Audit-Source' => 'Glueful'
                ],
                'json' => $event->toArray(),
                'timeout' => 5 // Short timeout to avoid blocking
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Exception $e) {
            // Log error but don't throw exception
            parent::error("Failed to send audit event to external service: " . $e->getMessage(), [
                'event_id' => $event->getEventId(),
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Check if audit logging should be skipped for this event
     *
     * @param AuditEvent $event Event to check
     * @return bool True if should skip, false otherwise
     */
    protected function shouldSkipAuditLog(AuditEvent $event): bool
    {
        $skipPaths = config('logging.audit.skip_paths', []);
        $skipUserAgents = config('logging.audit.skip_user_agents', []);

        // Check if request URI matches any skip paths
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        foreach ($skipPaths as $path) {
            if (strpos($requestUri, $path) === 0) {
                return true;
            }
        }

        // Check if user agent matches any skip patterns
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        foreach ($skipUserAgents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create an audit event and log it
     *
     * @param string $category Event category (use AuditEvent::CATEGORY_* constants)
     * @param string $action Specific action being audited
     * @param string $severity Event severity level (use AuditEvent::SEVERITY_* constants)
     * @param array $details Additional event details
     * @return string Event ID
     */
    public function audit(
        string $category,
        string $action,
        string $severity = AuditEvent::SEVERITY_INFO,
        array $details = []
    ): string {

        // Prevent recursive logging
        if (self::$isLogging) {
            return 'recursive-' . uniqid();
        }

        // Auto-detect high-volume categories and use async processing
        if (in_array($category, $this->highVolumeCategories) && !in_array($category, $this->criticalCategories)) {
            // Use async processing for high-volume, non-critical events
            return $this->asyncAudit($category, $action, $severity, $details);
        }

        // Create a new audit event
        $event = new AuditEvent($category, $action, $severity, $details);

        // Use RequestUserContext to avoid repeated authentication queries
        try {
            self::$isLogging = true;
            $userContext = RequestUserContext::getInstance()->initialize();

            if ($userContext->isAuthenticated()) {
                $event->setActor($userContext->getUserUuid());
            }
        } catch (\Exception $e) {
            // Silently handle auth errors - logging should continue even if auth fails
        } finally {
            self::$isLogging = false; // Always reset flag even if exceptions occur
        }

        // Log the event
        return $this->logAuditEvent($event);
    }

    /**
     * Log authentication-related audit event
     *
     * @param string $action Authentication action (login, logout, etc.)
     * @param string|null $userId User ID if available
     * @param array $details Additional details
     * @param string $severity Event severity
     * @return string Event ID
     */
    public function authEvent(
        string $action,
        ?string $userId = null,
        array $details = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): string {
        // Use a static local variable for recursion detection within this method
        static $localIsLogging = false;

        // Prevent recursive logging but only for deep recursion
        if ($localIsLogging) {
            return 'recursive-' . uniqid();
        }

        try {
            $localIsLogging = true;
            $event = new AuditEvent(AuditEvent::CATEGORY_AUTH, $action, $severity, $details);
            if ($userId) {
                $event->setActor($userId);
            }
            return $this->logAuditEvent($event);
        } finally {
            $localIsLogging = false;
        }
    }

    /**
     * Log authorization-related audit event
     *
     * @param string $action Authorization action (access granted/denied)
     * @param string|null $userId User ID if available
     * @param string|null $resourceId Resource being accessed
     * @param string|null $resourceType Type of resource
     * @param array $details Additional details
     * @param string $severity Event severity
     * @return string Event ID
     */
    public function authzEvent(
        string $action,
        ?string $userId = null,
        ?string $resourceId = null,
        ?string $resourceType = null,
        array $details = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): string {
        $event = new AuditEvent(AuditEvent::CATEGORY_AUTHZ, $action, $severity, $details);

        if ($userId) {
            $event->setActor($userId);
        }

        if ($resourceId && $resourceType) {
            $event->setTarget($resourceId, $resourceType);
        }

        return $this->logAuditEvent($event);
    }

    /**
     * Log data access audit event
     *
     * @param string $action Data access action (read, create, update, delete)
     * @param string|null $userId User ID if available
     * @param string|null $dataId Data record ID
     * @param string|null $dataType Type of data
     * @param array $details Additional details
     * @param string $severity Event severity
     * @return string Event ID
     */
    public function dataEvent(
        string $action,
        ?string $userId = null,
        ?string $dataId = null,
        ?string $dataType = null,
        array $details = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): string {
        // Prevent recursive logging
        if (self::$isLogging) {
            return 'recursive-' . uniqid();
        }

        try {
            self::$isLogging = true;

            $event = new AuditEvent(AuditEvent::CATEGORY_DATA, $action, $severity, $details);

            if ($userId) {
                $event->setActor($userId);
            }

            if ($dataId && $dataType) {
                $event->setTarget($dataId, $dataType);
            }

            return $this->logAuditEvent($event);
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Log administrative action audit event
     *
     * @param string $action Administrative action
     * @param string|null $userId User ID if available
     * @param array $details Additional details
     * @param string $severity Event severity
     * @return string Event ID
     */
    public function adminEvent(
        string $action,
        ?string $userId = null,
        array $details = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): string {
        $event = new AuditEvent(AuditEvent::CATEGORY_ADMIN, $action, $severity, $details);

        if ($userId) {
            $event->setActor($userId);
        }

        return $this->logAuditEvent($event);
    }

    /**
     * Log system configuration change audit event
     *
     * @param string $action Configuration action
     * @param string|null $userId User ID if available
     * @param string|null $configKey Configuration key changed
     * @param array $details Additional details
     * @param string $severity Event severity
     * @return string Event ID
     */
    public function configEvent(
        string $action,
        ?string $userId = null,
        ?string $configKey = null,
        array $details = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): string {
        $event = new AuditEvent(AuditEvent::CATEGORY_CONFIG, $action, $severity, $details);

        if ($userId) {
            $event->setActor($userId);
        }

        if ($configKey) {
            $event->setTarget($configKey, 'config');
        }

        return $this->logAuditEvent($event);
    }

    /**
     * Search audit logs with filtering
     *
     * @param array $filters Search filters
     * @param int $page Page number (1-based)
     * @param int $perPage Results per page
     * @return array Search results with pagination metadata
     */
    public function searchAuditLogs(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        if (!$this->storageBackends['database']) {
            throw new \RuntimeException("Database backend is required for audit log search");
        }

        // Start building query
        $query = $this->db->select($this->auditTable);

        // Apply filters
        if (!empty($filters['category'])) {
            $query->where(['category' => $filters['category']]);
        }

        if (!empty($filters['action'])) {
            $query->where(['action' => ['LIKE', "%{$filters['action']}%"]]);
        }

        if (!empty($filters['severity'])) {
            $query->where(['severity' => $filters['severity']]);
        }

        if (!empty($filters['actor_id'])) {
            $query->where(['actor_id' => $filters['actor_id']]);
        }

        if (!empty($filters['target_id'])) {
            $query->where(['target_id' => $filters['target_id']]);
        }

        if (!empty($filters['target_type'])) {
            $query->where(['target_type' => $filters['target_type']]);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('timestamp', $filters['start_date'], $filters['end_date']);
        } elseif (!empty($filters['start_date'])) {
            $query->where(['timestamp' => ['>=', $filters['start_date']]]);
        } elseif (!empty($filters['end_date'])) {
            $query->where(['timestamp' => ['<=', $filters['end_date']]]);
        }

        if (!empty($filters['ip_address'])) {
            $query->where(['ip_address' => $filters['ip_address']]);
        }

        if (!empty($filters['session_id'])) {
            $query->where(['session_id' => $filters['session_id']]);
        }

        // Search in details JSON
        if (!empty($filters['details_search'])) {
            $query->whereJsonContains('details', $filters['details_search']);
        }

        // Add sorting
        $query->orderBy(['timestamp' => 'DESC']);

        // Use the built-in pagination method which handles:
        // - Optimized count query
        // - Pagination metadata
        // - Limit and offset calculation
        $paginationResult = $query->paginate($page, $perPage);

        // Parse JSON details
        foreach ($paginationResult['data'] as &$result) {
            if (isset($result['details']) && is_string($result['details'])) {
                $result['details'] = json_decode($result['details'], true) ?? [];
            }
        }

        // Return results with pagination metadata in the expected format
        return [
            'data' => $paginationResult['data'],
            'pagination' => [
                'total' => $paginationResult['total'],
                'per_page' => $paginationResult['per_page'],
                'current_page' => $paginationResult['current_page'],
                'last_page' => $paginationResult['last_page'],
                'from' => $paginationResult['from'],
                'to' => $paginationResult['to']
            ]
        ];
    }

    /**
     * Generate compliance reports for audit logs
     *
     * @param string $reportType Type of report ('auth', 'data_access', 'admin', etc.)
     * @param string $startDate Report start date (YYYY-MM-DD)
     * @param string $endDate Report end date (YYYY-MM-DD)
     * @param array $options Additional report options
     * @return array Report data
     */
    public function generateComplianceReport(
        string $reportType,
        string $startDate,
        string $endDate,
        array $options = []
    ): array {
        if (!$this->storageBackends['database']) {
            throw new \RuntimeException("Database backend is required for compliance reporting");
        }

        // Determine which category to report on
        $category = match ($reportType) {
            'authentication' => AuditEvent::CATEGORY_AUTH,
            'authorization' => AuditEvent::CATEGORY_AUTHZ,
            'data_access' => AuditEvent::CATEGORY_DATA,
            'admin' => AuditEvent::CATEGORY_ADMIN,
            'configuration' => AuditEvent::CATEGORY_CONFIG,
            'system' => AuditEvent::CATEGORY_SYSTEM,
            default => throw new \InvalidArgumentException("Invalid report type: $reportType")
        };

        // Build query for the report
        $query = $this->db->select($this->auditTable)
            ->where(['category' => $category])
            ->where(['timestamp' => ['>=', $startDate . ' 00:00:00']])
            ->where(['timestamp' => ['<=', $endDate . ' 23:59:59']]);

        // Add additional filters if provided
        if (!empty($options['actions'])) {
            $query->whereIn('action', $options['actions']);
        }

        if (!empty($options['severity'])) {
            $query->where(['severity' => $options['severity']]);
        }

        // Get raw data
        $rawData = $query->orderBy(['timestamp' => 'ASC'])->get();

        // Process results based on report type
        $reportData = [
            'report_type' => $reportType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'generated_at' => (new \DateTimeImmutable())->format('c'),
            'summary' => [],
            'details' => []
        ];

        // Generate summary based on report type
        switch ($reportType) {
            case 'authentication':
                $reportData['summary'] = $this->summarizeAuthEvents($rawData);
                break;
            case 'data_access':
                $reportData['summary'] = $this->summarizeDataAccessEvents($rawData);
                break;
            case 'admin':
                $reportData['summary'] = $this->summarizeAdminEvents($rawData);
                break;
            default:
                // Basic summary for other types
                $reportData['summary'] = $this->summarizeGenericEvents($rawData);
        }

        // Include detailed events if requested
        if (!empty($options['include_details'])) {
            $reportData['details'] = $rawData;
        }

        return $reportData;
    }

    /**
     * Summarize authentication events
     *
     * @param array $events Events to summarize
     * @return array Summary data
     */
    protected function summarizeAuthEvents(array $events): array
    {
        $summary = [
            'total_events' => count($events),
            'login_attempts' => 0,
            'successful_logins' => 0,
            'failed_logins' => 0,
            'logouts' => 0,
            'password_changes' => 0,
            'mfa_events' => 0,
            'by_ip' => [],
            'by_user' => []
        ];

        foreach ($events as $event) {
            // Count by action type
            switch ($event['action']) {
                case 'login_attempt':
                    $summary['login_attempts']++;
                    break;
                case 'login_success':
                    $summary['successful_logins']++;
                    break;
                case 'login_failure':
                    $summary['failed_logins']++;
                    break;
                case 'logout':
                    $summary['logouts']++;
                    break;
                case 'password_change':
                    $summary['password_changes']++;
                    break;
                case (strpos($event['action'], 'mfa_') === 0):
                    $summary['mfa_events']++;
                    break;
            }

            // Count by IP
            if (!empty($event['ip_address'])) {
                if (!isset($summary['by_ip'][$event['ip_address']])) {
                    $summary['by_ip'][$event['ip_address']] = 0;
                }
                $summary['by_ip'][$event['ip_address']]++;
            }

            // Count by user
            if (!empty($event['actor_id'])) {
                if (!isset($summary['by_user'][$event['actor_id']])) {
                    $summary['by_user'][$event['actor_id']] = 0;
                }
                $summary['by_user'][$event['actor_id']]++;
            }
        }

        // Sort by frequency
        arsort($summary['by_ip']);
        arsort($summary['by_user']);

        return $summary;
    }

    /**
     * Summarize data access events
     *
     * @param array $events Events to summarize
     * @return array Summary data
     */
    protected function summarizeDataAccessEvents(array $events): array
    {
        $summary = [
            'total_events' => count($events),
            'reads' => 0,
            'creates' => 0,
            'updates' => 0,
            'deletes' => 0,
            'by_user' => [],
            'by_resource_type' => [],
            'by_severity' => []
        ];

        foreach ($events as $event) {
            // Count by action type
            switch ($event['action']) {
                case 'read':
                case 'view':
                case 'export':
                case 'download':
                    $summary['reads']++;
                    break;
                case 'create':
                case 'insert':
                    $summary['creates']++;
                    break;
                case 'update':
                case 'modify':
                    $summary['updates']++;
                    break;
                case 'delete':
                case 'remove':
                    $summary['deletes']++;
                    break;
            }

            // Count by user
            if (!empty($event['actor_id'])) {
                if (!isset($summary['by_user'][$event['actor_id']])) {
                    $summary['by_user'][$event['actor_id']] = 0;
                }
                $summary['by_user'][$event['actor_id']]++;
            }

            // Count by resource type
            if (!empty($event['target_type'])) {
                if (!isset($summary['by_resource_type'][$event['target_type']])) {
                    $summary['by_resource_type'][$event['target_type']] = 0;
                }
                $summary['by_resource_type'][$event['target_type']]++;
            }

            // Count by severity
            if (!empty($event['severity'])) {
                if (!isset($summary['by_severity'][$event['severity']])) {
                    $summary['by_severity'][$event['severity']] = 0;
                }
                $summary['by_severity'][$event['severity']]++;
            }
        }

        // Sort by frequency
        arsort($summary['by_user']);
        arsort($summary['by_resource_type']);

        return $summary;
    }

    /**
     * Summarize administrative events
     *
     * @param array $events Events to summarize
     * @return array Summary data
     */
    protected function summarizeAdminEvents(array $events): array
    {
        $summary = [
            'total_events' => count($events),
            'by_action' => [],
            'by_admin' => [],
            'by_day' => []
        ];

        foreach ($events as $event) {
            // Count by action
            if (!empty($event['action'])) {
                if (!isset($summary['by_action'][$event['action']])) {
                    $summary['by_action'][$event['action']] = 0;
                }
                $summary['by_action'][$event['action']]++;
            }

            // Count by admin user
            if (!empty($event['actor_id'])) {
                if (!isset($summary['by_admin'][$event['actor_id']])) {
                    $summary['by_admin'][$event['actor_id']] = 0;
                }
                $summary['by_admin'][$event['actor_id']]++;
            }

            // Count by day
            if (!empty($event['timestamp'])) {
                $day = substr($event['timestamp'], 0, 10); // YYYY-MM-DD
                if (!isset($summary['by_day'][$day])) {
                    $summary['by_day'][$day] = 0;
                }
                $summary['by_day'][$day]++;
            }
        }

        // Sort by frequency
        arsort($summary['by_action']);
        arsort($summary['by_admin']);
        ksort($summary['by_day']);

        return $summary;
    }

    /**
     * Summarize generic events
     *
     * @param array $events Events to summarize
     * @return array Summary data
     */
    protected function summarizeGenericEvents(array $events): array
    {
        $summary = [
            'total_events' => count($events),
            'by_action' => [],
            'by_severity' => [],
            'by_day' => []
        ];

        foreach ($events as $event) {
            // Count by action
            if (!empty($event['action'])) {
                if (!isset($summary['by_action'][$event['action']])) {
                    $summary['by_action'][$event['action']] = 0;
                }
                $summary['by_action'][$event['action']]++;
            }

            // Count by severity
            if (!empty($event['severity'])) {
                if (!isset($summary['by_severity'][$event['severity']])) {
                    $summary['by_severity'][$event['severity']] = 0;
                }
                $summary['by_severity'][$event['severity']]++;
            }

            // Count by day
            if (!empty($event['timestamp'])) {
                $day = substr($event['timestamp'], 0, 10); // YYYY-MM-DD
                if (!isset($summary['by_day'][$day])) {
                    $summary['by_day'][$day] = 0;
                }
                $summary['by_day'][$day]++;
            }
        }

        // Sort by frequency
        arsort($summary['by_action']);
        arsort($summary['by_severity']);
        ksort($summary['by_day']);

        return $summary;
    }

    /**
     * Enforce retention policy by purging expired audit logs
     *
     * @return int Number of purged records
     */
    public function enforceRetentionPolicy(): int
    {
        if (!$this->storageBackends['database']) {
            return 0;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            // Only delete non-immutable records if immutable storage is enabled
            $query = $this->db->select($this->auditTable)
                ->where(['retention_date' => ['<', $now]]);

            if ($this->immutableStorage) {
                $query->where(['immutable' => false]);
            }

            $count = $query->count('*');
            $query->delete($this->auditTable, []);

            return $count;
        } catch (\Exception $e) {
            // Log error but don't throw exception
            parent::error("Failed to enforce audit log retention policy: " . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get a logger for a specific channel
     *
     * Implementation of the parent method to maintain compatibility.
     *
     * @param string $channel
     * @return LoggerInterface
     */
    public function getLogger(string $channel): LoggerInterface
    {
        return parent::getLogger($channel);
    }

    /**
     * Add audit event to batch queue
     *
     * @param AuditEvent $event Event to add to batch
     * @return void
     */
    protected function addToBatch(AuditEvent $event): void
    {
        self::$batchQueue[] = $event;

        // Get category-specific batch settings
        $category = $event->getCategory();
        $batchSize = $this->getCategoryBatchSize($category);
        $batchTimeout = $this->getCategoryBatchTimeout($category);

        $shouldFlush = count(self::$batchQueue) >= $batchSize ||
                       (self::$lastBatchFlush > 0 && (time() - self::$lastBatchFlush) >= $batchTimeout);

        if ($shouldFlush) {
            $this->flushBatch();
        }
    }

    /**
     * Get category-specific batch size
     */
    protected function getCategoryBatchSize(string $category): int
    {
        return match (strtolower($category)) {
            'auth' => (int) config('app.audit.auth_event_batch_size', 100),
            'resource_access' => (int) config('app.audit.resource_access_batch_size', 300),
            default => (int) config('app.audit.batch_size', 50)
        };
    }

    /**
     * Get category-specific batch timeout
     */
    protected function getCategoryBatchTimeout(string $category): int
    {
        return match (strtolower($category)) {
            'auth' => (int) config('app.audit.auth_event_batch_timeout', 3),
            'resource_access' => (int) config('app.audit.resource_access_batch_timeout', 15),
            default => (int) config('app.audit.batch_timeout', 5)
        };
    }

    /**
     * Flush batch queue to database
     *
     * @return void
     */
    protected function flushBatch(): void
    {
        if (empty(self::$batchQueue)) {
            return;
        }

        $events = self::$batchQueue;
        self::$batchQueue = [];
        self::$lastBatchFlush = time();

        // Generate a unique batch UUID for this batch
        $batchUuid = \Glueful\Helpers\Utils::generateNanoID(16);

        // Prepare batch data
        $batchData = [];
        foreach ($events as $event) {
            $eventData = $event->toArray();

            // Generate a unique UUID for the record
            $eventData['uuid'] = \Glueful\Helpers\Utils::generateNanoID();

            // Temporarily disable batch_uuid to avoid column errors
            // $eventData['batch_uuid'] = $batchUuid;

            // Format timestamp to MySQL DATETIME format (from ISO 8601)
            if (isset($eventData['timestamp'])) {
                try {
                    $dateTime = new \DateTime($eventData['timestamp']);
                    $eventData['timestamp'] = $dateTime->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    $eventData['timestamp'] = date('Y-m-d H:i:s');
                }
            }

            // Calculate retention date based on category
            $retentionDays = $this->retentionPolicies[$event->getCategory()] ?? 365;
            $retentionDate = (new \DateTimeImmutable())->modify("+{$retentionDays} days")->format('Y-m-d H:i:s');
            $eventData['retention_date'] = $retentionDate;

            // JSON encode details field
            if (isset($eventData['details'])) {
                $eventData['details'] = json_encode($eventData['details'], JSON_UNESCAPED_SLASHES);
            }

            $batchData[] = $eventData;
        }

        // Perform bulk insert
        try {
            // Use the new insertBatch method for better performance
            $this->db->insertBatch($this->auditTable, $batchData);
        } catch (\Exception $e) {
            // Log error but don't throw exception
            parent::error("Failed to flush audit batch: " . $e->getMessage(), [
                'batch_size' => count($batchData),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Queue audit event for async processing
     *
     * @param AuditEvent $event Event to queue
     * @return void
     */
    protected function queueAsyncAudit(AuditEvent $event): void
    {
        try {
            $queueConfig = config('app.audit.async_processing', []);
            $queueName = $queueConfig['queue_name'] ?? 'audit';

            // Get queue manager and dispatch
            $queueManager = new \Glueful\Queue\QueueManager();
            $queueManager->push(
                \Glueful\Queue\Jobs\ProcessAuditLog::class,
                $event->toArray(),
                $queueName
            );
        } catch (\Exception $e) {
            // Fallback to immediate processing if queue fails
            error_log("Failed to queue audit event, falling back to immediate processing: " . $e->getMessage());
            // Temporarily disable fallback to break the cycle
            // $this->storeAuditEventInDatabase($event);
        }
    }

    /**
     * Process audit event asynchronously for high-volume categories
     *
     * @param string $category Event category
     * @param string $action Event action
     * @param string $severity Event severity
     * @param array $details Additional event details
     * @return string Event ID
     */
    protected function asyncAudit(
        string $category,
        string $action,
        string $severity = AuditEvent::SEVERITY_INFO,
        array $details = []
    ): string {
        // Create a new audit event
        $event = new AuditEvent($category, $action, $severity, $details);

        // Use RequestUserContext to avoid repeated authentication queries
        try {
            self::$isLogging = true;
            $userContext = RequestUserContext::getInstance()->initialize();

            if ($userContext->isAuthenticated()) {
                $event->setActor($userContext->getUserUuid());
            }
        } catch (\Exception $e) {
            // Silently handle auth errors - logging should continue even if auth fails
        } finally {
            self::$isLogging = false;
        }

        // Temporarily disable async processing
        // $this->queueAsyncAudit($event);

        // Use immediate processing instead
        $this->storeAuditEventInDatabase($event);

        return $event->getEventId();
    }

    /**
     * Register shutdown function to flush batch on script end
     */
    public function __destruct()
    {
        if (!empty(self::$batchQueue)) {
            $this->flushBatch();
        }
    }
}
