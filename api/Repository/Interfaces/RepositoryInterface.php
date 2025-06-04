<?php

declare(strict_types=1);

namespace Glueful\Repository\Interfaces;

/**
 * Repository Interface
 *
 * Defines the complete contract for all repository implementations.
 * Provides comprehensive data access patterns with type safety and consistency.
 */
interface RepositoryInterface
{
    /**
     * Find a record by its UUID
     *
     * @param string $uuid The UUID of the record
     * @return array|null The record data or null if not found
     */
    public function find(string $uuid): ?array;

    /**
     * Find all records with optional filtering
     *
     * @param array $conditions Filter conditions
     * @param array $orderBy Sorting criteria
     * @param int|null $limit Maximum number of records to return
     * @param int|null $offset Number of records to skip
     * @return array Array of records
     */
    public function findAll(
        array $conditions = [],
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null
    ): array;

    /**
     * Create a new record
     *
     * @param array $data The data to insert
     * @return string The UUID of the created record
     */
    public function create(array $data): string;

    /**
     * Update a record by UUID
     *
     * @param string $uuid The UUID of the record to update
     * @param array $data The data to update
     * @return bool True if the update was successful
     */
    public function update(string $uuid, array $data): bool;

    /**
     * Delete a record by UUID
     *
     * @param string $uuid The UUID of the record to delete
     * @return bool True if the deletion was successful
     */
    public function delete(string $uuid): bool;

    /**
     * Count records with optional conditions
     *
     * @param array $conditions Filter conditions
     * @return int Number of matching records
     */
    public function count(array $conditions = []): int;

    /**
     * Check if a record exists by UUID
     *
     * @param string $uuid The UUID to check
     * @return bool True if the record exists
     */
    public function exists(string $uuid): bool;

    /**
     * Get paginated results
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Number of records per page
     * @param array $conditions Filter conditions
     * @param array $orderBy Sorting criteria
     * @param array $fields Fields to select (empty array = all fields)
     * @return array Paginated result with data and metadata
     */
    public function paginate(
        int $page,
        int $perPage,
        array $conditions = [],
        array $orderBy = [],
        array $fields = []
    ): array;

    /**
     * Find records with complex where conditions
     *
     * @param array $where Complex where conditions
     * @param array $orderBy Sorting criteria
     * @param int|null $limit Maximum number of records
     * @return array Array of matching records
     */
    public function findWhere(array $where, array $orderBy = [], ?int $limit = null): array;

    /**
     * Bulk insert multiple records
     *
     * @param array $records Array of record data
     * @return array Array of created UUIDs
     */
    public function bulkCreate(array $records): array;

    /**
     * Bulk update records by UUIDs
     *
     * @param array $uuids Array of UUIDs to update
     * @param array $data Data to update
     * @return int Number of affected records
     */
    public function bulkUpdate(array $uuids, array $data): int;

    /**
     * Bulk delete records by UUIDs
     *
     * @param array $uuids Array of UUIDs to delete
     * @return int Number of affected records
     */
    public function bulkDelete(array $uuids): int;

    /**
     * Soft delete a record by setting status column
     *
     * @param string $uuid The UUID of the record
     * @param string $statusColumn The status column name
     * @param mixed $deletedValue The value to set for deleted status
     * @return bool True if successful
     */
    public function softDelete(string $uuid, string $statusColumn = 'status', $deletedValue = 'deleted'): bool;

    /**
     * Bulk soft delete records
     *
     * @param array $uuids Array of UUIDs to soft delete
     * @param string $statusColumn The status column name
     * @param mixed $deletedValue The value to set for deleted status
     * @return int Number of affected records
     */
    public function bulkSoftDelete(array $uuids, string $statusColumn = 'status', $deletedValue = 'deleted'): int;

    /**
     * Get the table name for this repository
     *
     * @return string The table name
     */
    public function getTableName(): string;
}
