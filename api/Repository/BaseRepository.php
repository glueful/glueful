<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
use Glueful\Repository\Interfaces\RepositoryInterface;
use Glueful\Helpers\Utils;

/**
 * Base Repository
 *
 * Unified repository base class combining the best features:
 * - Modern interface implementation with type safety
 * - Rich audit logging and security features
 * - Transaction support and connection management
 * - Pagination and advanced querying
 * - UUID-first approach with fallback support
 *
 * @package Glueful\Repository
 */
abstract class BaseRepository implements RepositoryInterface
{
    /** @var QueryBuilder Database query builder instance */
    protected QueryBuilder $db;

    /** @var string Name of the primary database table for this repository */
    protected string $table;

    /** @var string Primary key field name, defaults to 'uuid' */
    protected string $primaryKey = 'uuid';

    /** @var bool Whether this repository handles sensitive data requiring stricter auditing */
    protected bool $containsSensitiveData = false;

    /** @var array Fields that should be considered sensitive and tracked in audit logs */
    protected array $sensitiveFields = [];

    /** @var array Standard fields to retrieve in queries */
    protected array $defaultFields = ['*'];

    /** @var AuditLogger|null Audit logger instance */
    protected ?AuditLogger $auditLogger = null;

    /** @var bool Whether this table has updated_at timestamp column */
    protected bool $hasUpdatedAt = true;

    /** @var Connection|null Shared database connection across all repositories */
    private static ?Connection $sharedConnection = null;

    /**
     * Get shared database connection
     *
     * Returns the shared connection instance across all repositories,
     * creating it if needed. This ensures connection reuse.
     *
     * @return Connection The shared database connection
     */
    protected static function getSharedConnection(): Connection
    {
        return self::$sharedConnection ??= new Connection();
    }

    /**
     * Get new query builder instance
     *
     * Returns a new query builder instance for clean queries with pooled connection support.
     * This prevents query state from persisting between operations while enabling connection
     * pooling when available.
     *
     * @return QueryBuilder A new query builder instance
     */
    protected static function getNewQueryBuilder(): QueryBuilder
    {
        $conn = self::getSharedConnection();

        // Pass the PDO connection (which may be pooled) directly to QueryBuilder
        // QueryBuilder will detect if it's a PooledConnection and handle appropriately
        $queryBuilder = new QueryBuilder($conn->getPDO(), $conn->getDriver());

        // Enable debug mode if app is in debug mode
        if (config('app.debug')) {
            $queryBuilder->getLogger()->configure(enableDebug: true, enableTiming: true);
        }

        return $queryBuilder;
    }

    /**
     * Initialize repository
     *
     * Sets up database connection and query builder for common database operations.
     *
     * @param Connection|null $connection Optional connection override
     */
    public function __construct(?Connection $connection = null)
    {
        if ($connection) {
            self::$sharedConnection = $connection;
        }

        $this->db = self::getNewQueryBuilder();
        $this->table = $this->getTableName();

        // Get the audit logger instance
        $this->auditLogger = AuditLogger::getInstance();
    }

    /**
     * Get the table name for this repository
     * Must be implemented by concrete repositories
     *
     * @return string The table name
     */
    abstract public function getTableName(): string;

    /**
     * {@inheritdoc}
     */
    public function find(string $uuid): ?array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where([$this->primaryKey => $uuid])
            ->limit(1)
            ->get();

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Find record by UUID (standardized method)
     *
     * This method provides a consistent interface for finding records by UUID
     * across all repositories, eliminating duplication and providing a standard
     * naming convention. Uses a different name to avoid conflicts with existing
     * repository implementations that return model objects.
     *
     * @param string $uuid The UUID to search for
     * @param array|null $fields Fields to retrieve (optional, defaults to defaultFields)
     * @return array|null Record data or null if not found
     */
    public function findRecordByUuid(string $uuid, ?array $fields = null): ?array
    {
        return $this->findBy($this->primaryKey, $uuid, $fields);
    }

    /**
     * Find record by slug (standardized method)
     *
     * This method provides a consistent interface for finding records by slug
     * across all repositories that have slug fields, eliminating duplication.
     *
     * @param string $slug The slug to search for
     * @param array|null $fields Fields to retrieve (optional, defaults to defaultFields)
     * @return array|null Record data or null if not found
     */
    public function findBySlug(string $slug, ?array $fields = null): ?array
    {
        return $this->findBy('slug', $slug, $fields);
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(array $conditions = [], array $orderBy = [], ?int $limit = null, ?int $offset = null): array
    {
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where($conditions);

        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }

        if ($limit !== null) {
            $query->limit($limit);
            if ($offset !== null) {
                $query->offset($offset);
            }
        }

        return $query->get();
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data): string
    {
        // Generate UUID if not provided
        if (!isset($data[$this->primaryKey])) {
            $data[$this->primaryKey] = Utils::generateNanoID();
        }

        // Add timestamps if not present
        if (!isset($data['created_at'])) {
            $data['created_at'] = $this->db->getDriver()->formatDateTime();
        }
        if ($this->hasUpdatedAt && !isset($data['updated_at'])) {
            $data['updated_at'] = $this->db->getDriver()->formatDateTime();
        }

        // Execute the insert
        $success = $this->db->insert($this->table, $data);

        if (!$success) {
            throw new \RuntimeException('Failed to create record');
        }

        $uuid = $data[$this->primaryKey];

        // Audit log the creation
        $this->auditDataAction('create', $uuid, $data);

        return $uuid;
    }

    /**
     * {@inheritdoc}
     */
    public function update(string $uuid, array $data): bool
    {
        // Add updated timestamp if table has this column
        if ($this->hasUpdatedAt) {
            $data['updated_at'] = $this->db->getDriver()->formatDateTime();
        }

        // Execute the update
        $affectedRows = $this->db->update(
            $this->table,
            $data,
            [$this->primaryKey => $uuid]
        );

        $success = $affectedRows > 0;

        if ($success) {
            // Audit log the update (without original data comparison)
            $this->auditDataAction('update', $uuid, $data);
        }

        return $success;
    }


    /**
     * {@inheritdoc}
     */
    public function delete(string $uuid): bool
    {
        // Get the record before deleting (for audit log)
        $originalData = $this->find($uuid);

        if (!$originalData) {
            return false;
        }

        // Execute the delete
        $affectedRows = $this->db->delete(
            $this->table,
            [$this->primaryKey => $uuid]
        );

        $success = $affectedRows > 0;

        if ($success) {
            // Audit log the deletion
            $this->auditDataAction('delete', $uuid, [], null, $originalData);
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $conditions = []): int
    {
        return $this->db->count($this->table, $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $uuid): bool
    {
        return $this->count([$this->primaryKey => $uuid]) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function paginate(
        int $page,
        int $perPage,
        array $conditions = [],
        array $orderBy = [],
        array $fields = []
    ): array {
        // Use QueryBuilder's built-in pagination
        $query = $this->db->select(
            $this->table,
            !empty($fields) ? $fields : $this->defaultFields
        );

        // Only add conditions if they exist
        if (!empty($conditions)) {
            $query->where($conditions);
        }

        // Add ordering if specified
        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }

        // Execute paginate on the query builder
        return $query->paginate($page, $perPage);
    }

    /**
     * {@inheritdoc}
     */
    public function findWhere(array $where, array $orderBy = [], ?int $limit = null): array
    {
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where($where);

        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findMultiple(array $uuids, array $fields = []): array
    {
        if (empty($uuids)) {
            return [];
        }

        $results = $this->db->select(
            $this->table,
            !empty($fields) ? $fields : $this->defaultFields
        )
            ->where([$this->primaryKey => ['IN', $uuids]])
            ->get();

        // Index results by UUID
        $indexed = [];
        foreach ($results as $record) {
            if (isset($record[$this->primaryKey])) {
                $indexed[$record[$this->primaryKey]] = $record;
            }
        }

        return $indexed;
    }

    /**
     * {@inheritdoc}
     */
    public function bulkCreate(array $records): array
    {
        if (empty($records)) {
            return [];
        }

        $uuids = [];
        $bulkData = [];

        // Prepare data and generate UUIDs
        foreach ($records as $record) {
            if (!isset($record[$this->primaryKey])) {
                $record[$this->primaryKey] = Utils::generateNanoID();
            }
            $uuids[] = $record[$this->primaryKey];

            // Add timestamps if needed
            if (!isset($record['created_at'])) {
                $record['created_at'] = date('Y-m-d H:i:s');
            }
            if ($this->hasUpdatedAt && !isset($record['updated_at'])) {
                $record['updated_at'] = date('Y-m-d H:i:s');
            }

            $bulkData[] = $record;
        }

        $this->beginTransaction();
        try {
            // Use database bulk insert for better performance
            $success = $this->db->insertBatch($this->table, $bulkData);
            if (!$success) {
                throw new \RuntimeException('Bulk insert failed');
            }

            $this->commit();

            // Single audit log for bulk operation
            if ($this->auditLogger) {
                $this->auditLogger->audit(
                    AuditEvent::CATEGORY_DATA,
                    'bulk_create',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'table' => $this->table,
                        'record_count' => count($records),
                        'uuids' => $uuids
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }

        return $uuids;
    }

    /**
     * {@inheritdoc}
     */
    public function bulkUpdate(array $uuids, array $data): int
    {
        if (empty($uuids)) {
            return 0;
        }

        if ($this->hasUpdatedAt && !isset($data['updated_at'])) {
            $data['updated_at'] = $this->db->getDriver()->formatDateTime();
        }

        $affectedRows = $this->db->update(
            $this->table,
            $data,
            [$this->primaryKey => ['IN', $uuids]]
        );

        // Audit log bulk update
        $this->auditDataAction('bulk_update', implode(',', $uuids), $data);

        return $affectedRows;
    }

    /**
     * {@inheritdoc}
     */
    public function bulkDelete(array $uuids): int
    {
        if (empty($uuids)) {
            return 0;
        }

        $affectedRows = $this->db->delete(
            $this->table,
            [$this->primaryKey => ['IN', $uuids]]
        );

        // Ensure we return an integer count
        $count = is_bool($affectedRows) ? ($affectedRows ? count($uuids) : 0) : $affectedRows;

        // Audit log bulk delete
        $this->auditDataAction('bulk_delete', implode(',', $uuids), []);

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function softDelete(string $uuid, string $statusColumn = 'status', $deletedValue = 'deleted'): bool
    {
        $updateData = [$statusColumn => $deletedValue];
        if ($this->hasUpdatedAt) {
            $updateData['updated_at'] = $this->db->getDriver()->formatDateTime();
        }
        $success = $this->update($uuid, $updateData);

        if ($success) {
            // Audit log soft delete
            $this->auditDataAction('soft_delete', $uuid, [$statusColumn => $deletedValue]);
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function bulkSoftDelete(array $uuids, string $statusColumn = 'status', $deletedValue = 'deleted'): int
    {
        $affectedRows = $this->bulkUpdate($uuids, [
            $statusColumn => $deletedValue
        ]);

        // Audit log bulk soft delete
        $this->auditDataAction('bulk_soft_delete', implode(',', $uuids), [$statusColumn => $deletedValue]);

        return $affectedRows;
    }

    // Legacy methods for backward compatibility

    /**
     * Find a record by a specific field value (legacy support)
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array|null $fields Fields to retrieve
     * @return array|null Record data or null if not found
     */
    public function findBy(string $field, $value, ?array $fields = null): ?array
    {
        $query = $this->db->select($this->table, $fields ?? $this->defaultFields)
            ->where([$field => $value])
            ->limit(1)
            ->get();

        return $query ? $query[0] : null;
    }

    /**
     * Find all records matching a specific field value (legacy support)
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param array|null $fields Fields to retrieve
     * @return array Records data
     */
    public function findAllBy(string $field, $value, ?array $fields = null): array
    {
        return $this->db->select($this->table, $fields ?? $this->defaultFields)
            ->where([$field => $value])
            ->get();
    }

    /**
     * Begin a database transaction
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit a database transaction
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Roll back a database transaction
     */
    public function rollBack(): bool
    {
        return $this->db->rollback();
    }

    /**
     * Get the query builder instance
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder(): QueryBuilder
    {
        return $this->db;
    }

    /**
     * Create a query builder instance with the repository's table
     * This is useful for building more complex queries
     *
     * @param array|null $fields Fields to retrieve
     * @return QueryBuilder
     */
    public function query(?array $fields = null): QueryBuilder
    {
        return $this->db->select(
            $this->table,
            $fields ?? $this->defaultFields
        );
    }

    /**
     * Audit log a data action
     *
     * @param string $action The action being performed (create, update, delete, etc.)
     * @param string $resourceId ID of the affected resource
     * @param array $newData New data being applied
     * @param string|null $userId ID of user performing the action (if available)
     * @param array|null $originalData Original data before changes (for updates/deletes)
     */
    protected function auditDataAction(
        string $action,
        string $resourceId,
        array $newData,
        ?string $userId = null,
        ?array $originalData = null
    ): void {
        if ($this->auditLogger === null) {
            return;
        }

        $severity = AuditEvent::SEVERITY_INFO;
        $entityType = $this->table;
        $context = [
            'entity_id' => $resourceId,
            'entity_type' => $entityType,
            'user_id' => $userId
        ];

        // For sensitive data, add special handling
        if ($this->containsSensitiveData) {
            $severity = AuditEvent::SEVERITY_WARNING;

            // Don't include the actual sensitive field values, just note which fields were changed
            if (!empty($this->sensitiveFields)) {
                $changedSensitiveFields = array_intersect(array_keys($newData), $this->sensitiveFields);
                if (!empty($changedSensitiveFields)) {
                    $context['sensitive_fields_modified'] = $changedSensitiveFields;
                }
            }
        } else {
            // For non-sensitive data, include the changes
            switch ($action) {
                case 'update':
                    // Only include the fields that actually changed
                    if ($originalData) {
                        $changes = [];
                        foreach ($newData as $field => $value) {
                            if (isset($originalData[$field]) && $originalData[$field] !== $value) {
                                // Exclude any sensitive fields from the change log
                                if (!in_array($field, $this->sensitiveFields)) {
                                    $changes[$field] = [
                                        'old' => $originalData[$field],
                                        'new' => $value
                                    ];
                                }
                            }
                        }
                        $context['changes'] = $changes;
                    }
                    break;
                case 'create':
                    // Filter out sensitive fields
                    $safeData = array_diff_key($newData, array_flip($this->sensitiveFields));
                    $context['data'] = $safeData;
                    break;
                case 'delete':
                    // Include the deleted data for tracking
                    if ($originalData) {
                        // Filter out sensitive fields
                        $safeData = array_diff_key($originalData, array_flip($this->sensitiveFields));
                        $context['deleted_data'] = $safeData;
                    }
                    break;
            }
        }

        // Get caller info from debug backtrace for improved audit trail
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        if (isset($backtrace[1])) {
            $caller = $backtrace[1];
            $context['source'] = [
                'class' => $caller['class'] ?? null,
                'function' => $caller['function'],
            ];
        }

        // Add request information if available (not in CLI mode)
        if (php_sapi_name() !== 'cli' && !empty($_SERVER)) {
            $context['request'] = [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ];
        }

        // Log the event
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_DATA,
            "{$action}_{$entityType}",
            $severity,
            $context
        );
    }
}
