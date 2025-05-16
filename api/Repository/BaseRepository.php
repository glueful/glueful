<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;

/**
 * Base Repository
 *
 * Abstract base class for all repositories implementing common database operations
 * with integrated audit logging. This class provides standard CRUD operations with
 * security event tracking for data manipulation actions.
 *
 * Features:
 * - Standardized database operations (create, read, update, delete)
 * - Integrated audit logging for all data modifications
 * - Transaction support for atomic operations
 * - Query builder access for custom operations
 * - Support for entity tracking and relationship management
 *
 * @package Glueful\Repository
 */
abstract class BaseRepository
{
    /** @var QueryBuilder Database query builder instance */
    protected QueryBuilder $db;

    /** @var string Name of the primary database table for this repository */
    protected string $table;

    /** @var string Primary key field name, defaults to 'id' */
    protected string $primaryKey = 'uuid';

    /** @var bool Whether this repository handles sensitive data requiring stricter auditing */
    protected bool $containsSensitiveData = false;

    /** @var array Fields that should be considered sensitive and tracked in audit logs */
    protected array $sensitiveFields = [];

    /** @var array Standard fields to retrieve in queries */
    protected array $defaultFields = ['*'];

    /** @var AuditLogger|null Audit logger instance */
    protected ?AuditLogger $auditLogger = null;

    /**
     * Initialize repository
     *
     * Sets up database connection and query builder
     * for common database operations.
     *
     * @param string|null $table Optional table name override
     */
    public function __construct(?string $table = null)
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        if ($table) {
            $this->table = $table;
        }

        // Get the audit logger instance
        $this->auditLogger = AuditLogger::getInstance();
    }

    /**
     * Find a record by a specific field value
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
     * Find all records matching a specific field value
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
     * Get all records
     *
     * @param array|null $fields Fields to retrieve
     * @return array All records
     */
    public function getAll(?array $fields = null): array
    {
        return $this->db->select($this->table, $fields ?? $this->defaultFields)
            ->get();
    }

    /**
     * Create a new record
     *
     * @param array $data Record data
     * @param string|null $userId ID of user performing the action
     * @return string|int ID of created record
     */
    public function create(array $data, ?string $userId = null)
    {
        // Execute the insert
        $id = $this->db->insert($this->table, $data);

        // Audit log the creation
        $this->auditDataAction('create', $id, $data, $userId);

        return $id;
    }

    /**
     * Update an existing record
     *
     * @param string|int $id Record identifier
     * @param array $data Updated data
     * @param string|null $userId ID of user performing the action
     * @return bool Success status
     */
    public function update($id, array $data, ?string $userId = null): bool
    {
        // Get the original record before updating (for audit comparison)
        $originalData = $this->findBy($this->primaryKey, $id);

        // Execute the update
        $result = $this->db->update($this->table, $data, [$this->primaryKey => $id]);

        // Audit log the update with the changes
        $this->auditDataAction('update', $id, $data, $userId, $originalData);

        return $result > 0;
    }

    /**
     * Delete a record
     *
     * @param string|int $id Record identifier
     * @param string|null $userId ID of user performing the action
     * @return bool Success status
     */
    public function delete($id, ?string $userId = null): bool
    {
        // Get the record before deleting (for audit log)
        $originalData = $this->findBy($this->primaryKey, $id);

        // Execute the delete
        $result = $this->db->delete($this->table, [$this->primaryKey => $id]);

        // Audit log the deletion
        $this->auditDataAction('delete', $id, [], $userId, $originalData);

        return $result > 0;
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
     *
     * @return bool Success status
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
     * Perform a paginated query
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @param array|null $fields Fields to retrieve
     * @param array $conditions WHERE conditions
     * @param array $orderBy Order by specification
     * @return array Paginated results with metadata
     */
    public function paginate(
        int $page = 1,
        int $perPage = 15,
        ?array $fields = null,
        array $conditions = [],
        array $orderBy = []
    ): array {
        // Select with the table, fields and conditions
        $query = $this->db->select(
            $this->table,
            $fields ?? $this->defaultFields,
            $conditions
        );

        // Add ordering if specified
        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }

        // Execute paginate on the query builder
        $result = $query->paginate($page, $perPage);
        return $result;
    }

    /**
     * Audit log a data action
     *
     * @param string $action The action being performed (create, update, delete, etc.)
     * @param string|int $resourceId ID of the affected resource
     * @param array $newData New data being applied
     * @param string|null $userId ID of user performing the action (if available)
     * @param array|null $originalData Original data before changes (for updates/deletes)
     * @return void
     */
    protected function auditDataAction(
        string $action,
        $resourceId,
        array $newData,
        ?string $userId = null,
        ?array $originalData = null
    ): void {
        if ($this->auditLogger === null) {
            return;
        }
        // Memory optimization: avoid including large datasets in audit logs
        $auditDataLimit = 1024; // Limit data size to avoid memory issues

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
                'class' => isset($caller['class']) ? $caller['class'] : null,
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
