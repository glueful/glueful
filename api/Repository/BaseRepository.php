<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Repository\Interfaces\RepositoryInterface;
use Glueful\Repository\Traits\TransactionTrait;
use Glueful\Helpers\Utils;
use Glueful\Exceptions\DatabaseException;
use Glueful\Events\Database\EntityCreatedEvent;
use Glueful\Events\Database\EntityUpdatedEvent;
use Glueful\Events\Event;

/**
 * Base Repository
 *
 * Unified repository base class combining the best features:
 * - Modern interface implementation with type safety
 * - Transaction support and connection management
 * - Pagination and advanced querying
 * - UUID-first approach with fallback support
 *
 * @package Glueful\Repository
 */
abstract class BaseRepository implements RepositoryInterface
{
    use TransactionTrait;

    /** @var Connection Database connection instance */
    protected Connection $db;

    /** @var string Name of the primary database table for this repository */
    protected string $table;

    /** @var string Primary key field name, defaults to 'uuid' */
    protected string $primaryKey = 'uuid';


    /** @var array Standard fields to retrieve in queries */
    protected array $defaultFields = ['*'];


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
     * Get shared database connection
     *
     * Returns the shared connection instance for fluent query building.
     * This ensures connection reuse and enables connection pooling.
     *
     * @return Connection The shared database connection
     */
    protected static function getSharedDb(): Connection
    {
        return self::getSharedConnection();
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

        $this->db = self::getSharedDb();
        $this->table = $this->getTableName();
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
        $results = $this->db->table($this->table)
            ->select($this->defaultFields)
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
        $query = $this->db->table($this->table)
            ->select($this->defaultFields)
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
     * Create a new record with automatic UUID generation and timestamps
     *
     * Creates a new database record with automatic UUID generation, timestamp
     * management, and event dispatching. Ensures data integrity and provides
     * comprehensive error handling.
     *
     * **Process:**
     * 1. Generate UUID if not provided (using NanoID for uniqueness)
     * 2. Add created_at and updated_at timestamps automatically
     * 3. Execute database insert operation
     * 4. Dispatch EntityCreatedEvent for downstream processing
     * 5. Return the generated or provided UUID
     *
     * **Automatic Field Management:**
     * - UUID: Generated using Utils::generateNanoID() if not provided
     * - created_at: Set to current timestamp if not provided
     * - updated_at: Set to current timestamp if table supports it
     *
     * @param array $data Record data to insert (UUID optional, will be generated)
     * @return string The UUID of the created record
     * @throws \Glueful\Exceptions\DatabaseException If database insert fails
     * @throws \InvalidArgumentException If required data fields are missing
     * @throws \RuntimeException If UUID generation fails
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
        $success = $this->db->table($this->table)->insert($data);

        if (!$success) {
            throw DatabaseException::createFailed($this->table);
        }

        $uuid = $data[$this->primaryKey];

        // Dispatch entity created event
        Event::dispatch(new EntityCreatedEvent($data, $this->table, [
            'entity_id' => $uuid,
            'timestamp' => time(),
            'primary_key' => $this->primaryKey,
            'operation' => 'create'
        ]));

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
        $affectedRows = $this->db->table($this->table)
            ->where([$this->primaryKey => $uuid])
            ->update($data);

        $success = $affectedRows > 0;

        if ($success) {
            // Dispatch entity updated event
            // Construct entity data with ID for the event
            $entityData = array_merge($data, [$this->primaryKey => $uuid]);

            Event::dispatch(new EntityUpdatedEvent($entityData, $this->table, $data, [
                'entity_id' => $uuid,
                'timestamp' => time(),
                'primary_key' => $this->primaryKey,
                'affected_rows' => $affectedRows,
                'operation' => 'update'
            ]));
        }

        return $success;
    }


    /**
     * {@inheritdoc}
     */
    public function delete(string $uuid): bool
    {
        // Get the record before deleting
        $originalData = $this->find($uuid);

        if (!$originalData) {
            return false;
        }

        // Execute the delete
        $affectedRows = $this->db->table($this->table)
            ->where([$this->primaryKey => $uuid])
            ->delete();

        $success = $affectedRows > 0;

        if ($success) {
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $conditions = []): int
    {
        return $this->db->table($this->table)->where($conditions)->count();
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
        $query = $this->db->table($this->table)
            ->select(!empty($fields) ? $fields : $this->defaultFields);

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
        $query = $this->db->table($this->table)
            ->select($this->defaultFields)
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

        $results = $this->db->table($this->table)
            ->select(!empty($fields) ? $fields : $this->defaultFields)
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
     * Create multiple records in a single optimized transaction
     *
     * Performs bulk record creation with automatic UUID generation, timestamp
     * management, and transactional safety. Optimized for large datasets with
     * rollback protection on failure.
     *
     * **Performance Features:**
     * - Single database transaction for atomicity
     * - Batch insert for optimal database performance
     * - Automatic rollback on any failure
     * - Memory-efficient processing for large datasets
     *
     * **Process:**
     * 1. Validate input data array
     * 2. Generate UUIDs and timestamps for all records
     * 3. Begin database transaction
     * 4. Execute batch insert operation
     * 5. Commit transaction or rollback on failure
     * 6. Return array of generated UUIDs
     *
     * **Usage Examples:**
     * ```php
     * // Create multiple users
     * $userData = [
     *     ['name' => 'John Doe', 'email' => 'john@example.com'],
     *     ['name' => 'Jane Smith', 'email' => 'jane@example.com']
     * ];
     * $uuids = $repository->bulkCreate($userData);
     * ```
     *
     * @param array $records Array of record data arrays to insert
     * @return array Array of UUIDs for the created records
     * @throws \InvalidArgumentException If records array is malformed
     * @throws \RuntimeException If bulk insert operation fails
     * @throws \Glueful\Exceptions\DatabaseException If transaction fails
     * @throws \Exception If any database operation fails (triggers rollback)
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
            $success = $this->db->table($this->table)->insertBatch($bulkData);
            if (!$success) {
                throw new \RuntimeException('Bulk insert failed');
            }

            $this->commit();
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

        $affectedRows = $this->db->table($this->table)
            ->where([$this->primaryKey => ['IN', $uuids]])
            ->update($data);


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

        $affectedRows = $this->db->table($this->table)
            ->where([$this->primaryKey => ['IN', $uuids]])
            ->delete();

        // Ensure we return an integer count
        $count = is_bool($affectedRows) ? ($affectedRows ? count($uuids) : 0) : $affectedRows;


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
        $query = $this->db->table($this->table)
            ->select($fields ?? $this->defaultFields)
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
        return $this->db->table($this->table)
            ->select($fields ?? $this->defaultFields)
            ->where([$field => $value])
            ->get();
    }

    /**
     * Begin a database transaction
     *
     * Starts a new database transaction for atomic operations.
     * Must be paired with commit() or rollBack() to complete the transaction.
     *
     * @throws \PDOException If transaction cannot be started
     */
    public function beginTransaction(): void
    {
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
    }

    /**
     * Commit a database transaction
     *
     * Commits all operations performed within the current transaction,
     * making changes permanent in the database.
     *
     * @throws \PDOException If transaction commit fails
     */
    public function commit(): void
    {
        $pdo = $this->db->getPDO();
        $pdo->commit();
    }

    /**
     * Roll back a database transaction
     *
     * Reverts all operations performed within the current transaction,
     * restoring the database to its state before the transaction began.
     *
     * @return bool True if rollback succeeded, false otherwise
     * @throws \PDOException If rollback operation fails
     */
    public function rollBack(): bool
    {
        $pdo = $this->db->getPDO();
        return $pdo->rollBack();
    }

    /**
     * Get the database connection instance
     *
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->db;
    }
}
