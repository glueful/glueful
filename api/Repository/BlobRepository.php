<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;

/**
 * Blob Repository
 *
 * Handles all database operations related to file blobs:
 * - File metadata storage and retrieval
 * - File status management (active, deleted)
 * - File information queries for upload/download operations
 * - Blob cleanup and maintenance
 *
 * This repository extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality for file operations.
 *
 * @package Glueful\Repository
 */
class BlobRepository extends BaseRepository
{
    /** @var array Default fields to retrieve for blob operations */
    private array $defaultBlobFields = [
        'uuid', 'name', 'description', 'mime_type', 'size',
        'url', 'status', 'created_by', 'created_at', 'updated_at'
    ];

    /**
     * Initialize repository
     *
     * Sets up database connection and blob-specific configuration
     */
    public function __construct(?Connection $connection = null)
    {
        // Configure repository settings before calling parent
        $this->defaultFields = $this->defaultBlobFields;
        $this->hasUpdatedAt = true;

        // Call parent constructor to set up database connection
        parent::__construct($connection);
    }

    /**
     * Get the table name for this repository
     *
     * @return string The table name
     */
    public function getTableName(): string
    {
        return 'blobs';
    }

    /**
     * Find blob by UUID
     *
     * Retrieves blob metadata using the unique identifier.
     * Returns null if blob doesn't exist or is marked as deleted.
     *
     * @param string $uuid Blob UUID to search for
     * @param array|null $fields Optional array of specific fields to retrieve
     * @return array|null Blob data or null if not found
     */
    public function findByUuid(string $uuid, ?array $fields = null): ?array
    {
        return parent::findRecordByUuid($uuid, $fields);
    }

    /**
     * Find blob by UUID with delete filtering
     *
     * Extended version that allows filtering of deleted blobs
     *
     * @param string $uuid The UUID to search for
     * @param bool $includeDeleted Whether to include deleted blobs
     * @return array|null Blob data or null if not found
     */
    public function findByUuidWithDeleteFilter(string $uuid, bool $includeDeleted = false): ?array
    {
        $conditions = ['uuid' => $uuid];

        if (!$includeDeleted) {
            $conditions['status'] = ['!=', 'deleted'];
        }

        return $this->findWhere($conditions, [], 1)[0] ?? null;
    }

    /**
     * Get blob information with specific fields
     *
     * Retrieves blob metadata with custom field selection.
     * Used by FilesController for optimized file operations.
     *
     * @param string $uuid Blob UUID
     * @param array $fields Specific fields to retrieve
     * @return array|null Blob data or null if not found
     */
    public function getBlobInfo(string $uuid, array $fields = []): ?array
    {
        if (empty($fields)) {
            $fields = $this->defaultBlobFields;
        }

        $query = $this->db->table($this->getTableName())
            ->select($fields)
            ->where(['uuid' => $uuid])
            ->where(['status' => ['!=', 'deleted']])
            ->limit(1)
            ->get();

        return $query ? $query[0] : null;
    }

    /**
     * Create new blob record
     *
     * Inserts a new blob record with file metadata.
     * Used during file upload operations.
     *
     * @param array $blobData Blob metadata (name, mime_type, size, url, etc.)
     * @return string New blob UUID
     * @throws \InvalidArgumentException If validation fails
     */
    public function create(array $blobData): string
    {
        // Validate required fields
        $required = ['name', 'mime_type', 'size', 'url', 'created_by'];
        foreach ($required as $field) {
            if (empty($blobData[$field])) {
                throw new \InvalidArgumentException("Field '{$field}' is required");
            }
        }

        // Set default status if not provided
        if (!isset($blobData['status'])) {
            $blobData['status'] = 'active';
        }

        // Set created_at if not provided
        if (!isset($blobData['created_at'])) {
            $blobData['created_at'] = date('Y-m-d H:i:s');
        }

        // Use parent create method which handles UUID generation and audit logging
        return parent::create($blobData);
    }

    /**
     * Update blob status
     *
     * Updates the status of a blob (active, deleted, etc.).
     * Used for soft delete operations and status management.
     *
     * @param string $uuid Blob UUID
     * @param string $status New status ('active', 'deleted', etc.)
     * @return bool Success status
     */
    public function updateStatus(string $uuid, string $status): bool
    {
        return $this->update($uuid, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Soft delete blob
     *
     * Marks a blob as deleted without removing the database record.
     * Physical file deletion should be handled separately.
     *
     * @param string $uuid Blob UUID to delete
     * @param string $statusColumn Status column name (defaults to 'status')
     * @param mixed $deletedValue Value for deleted status (defaults to 'deleted')
     * @return bool Success status
     */
    public function softDelete(string $uuid, string $statusColumn = 'status', $deletedValue = 'deleted'): bool
    {
        return parent::softDelete($uuid, $statusColumn, $deletedValue);
    }

    /**
     * Find blobs by creator
     *
     * Retrieves all blobs created by a specific user.
     * Useful for user-specific file management.
     *
     * @param string $creatorUuid Creator's user UUID
     * @param bool $activeOnly Whether to return only active blobs
     * @param array $orderBy Sorting criteria
     * @param int|null $limit Maximum number of records
     * @return array Array of blob records
     */
    public function findByCreator(
        string $creatorUuid,
        bool $activeOnly = true,
        array $orderBy = ['created_at' => 'desc'],
        ?int $limit = null
    ): array {
        $conditions = ['created_by' => $creatorUuid];

        if ($activeOnly) {
            $conditions['status'] = 'active';
        }

        return $this->findWhere($conditions, $orderBy, $limit);
    }

    /**
     * Find blobs by mime type
     *
     * Retrieves blobs of a specific type (images, documents, etc.).
     * Useful for media galleries and type-specific operations.
     *
     * @param string $mimeType MIME type to filter by
     * @param bool $activeOnly Whether to return only active blobs
     * @param array $orderBy Sorting criteria
     * @param int|null $limit Maximum number of records
     * @return array Array of blob records
     */
    public function findByMimeType(
        string $mimeType,
        bool $activeOnly = true,
        array $orderBy = ['created_at' => 'desc'],
        ?int $limit = null
    ): array {
        $conditions = ['mime_type' => $mimeType];

        if ($activeOnly) {
            $conditions['status'] = 'active';
        }

        return $this->findWhere($conditions, $orderBy, $limit);
    }

    /**
     * Find blobs by mime type pattern
     *
     * Retrieves blobs matching a MIME type pattern (e.g., 'image/%').
     * Useful for broad category searches.
     *
     * @param string $pattern MIME type pattern (e.g., 'image/%', 'video/%')
     * @param bool $activeOnly Whether to return only active blobs
     * @param array $orderBy Sorting criteria
     * @param int|null $limit Maximum number of records
     * @return array Array of blob records
     */
    public function findByMimePattern(
        string $pattern,
        bool $activeOnly = true,
        array $orderBy = ['created_at' => 'desc'],
        ?int $limit = null
    ): array {
        $query = $this->db->table($this->getTableName())
            ->select($this->defaultFields)
            ->whereLike('mime_type', $pattern);

        if ($activeOnly) {
            $query->where(['status' => 'active']);
        }

        if (!empty($orderBy)) {
            $query->orderBy($orderBy);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get storage statistics
     *
     * Retrieves storage usage statistics for monitoring and cleanup.
     *
     * @param string|null $creatorUuid Optional creator filter
     * @return array Storage statistics
     */
    public function getStorageStats(?string $creatorUuid = null): array
    {
        $conditions = ['status' => 'active'];

        if ($creatorUuid) {
            $conditions['created_by'] = $creatorUuid;
        }

        // Get total count and size
        $stats = $this->db->table($this->getTableName())
            ->select([
                'COUNT(*) as total_files',
                'SUM(size) as total_size',
                'AVG(size) as average_size',
                'MAX(size) as largest_file',
                'MIN(size) as smallest_file'
            ])
            ->where($conditions)
            ->get();

        $result = $stats[0] ?? [
            'total_files' => 0,
            'total_size' => 0,
            'average_size' => 0,
            'largest_file' => 0,
            'smallest_file' => 0
        ];

        // Convert numeric strings to integers/floats
        $result['total_files'] = (int)$result['total_files'];
        $result['total_size'] = (int)$result['total_size'];
        $result['average_size'] = (float)$result['average_size'];
        $result['largest_file'] = (int)$result['largest_file'];
        $result['smallest_file'] = (int)$result['smallest_file'];

        return $result;
    }

    /**
     * Find orphaned blobs
     *
     * Finds blobs that are marked as deleted for cleanup operations.
     * Can be used for scheduled cleanup tasks.
     *
     * @param int $olderThanDays Only include blobs deleted more than X days ago
     * @param int|null $limit Maximum number of records to return
     * @return array Array of deleted blob records
     */
    public function findOrphanedBlobs(int $olderThanDays = 7, ?int $limit = null): array
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$olderThanDays} days"));

        $query = $this->db->table($this->getTableName())
            ->select($this->defaultFields)
            ->where(['status' => 'deleted'])
            ->where(['updated_at' => ['<', $cutoffDate]])
            ->orderBy(['updated_at' => 'asc']);

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Update blob metadata
     *
     * Updates blob information like name, description, etc.
     * Does not modify file-specific fields like size or MIME type.
     *
     * @param string $uuid Blob UUID
     * @param array $metadata Metadata to update (name, description)
     * @return bool Success status
     */
    public function updateMetadata(string $uuid, array $metadata): bool
    {
        // Remove fields that shouldn't be updated directly
        $allowedFields = ['name', 'description'];
        $updateData = array_intersect_key($metadata, array_flip($allowedFields));

        if (empty($updateData)) {
            return false;
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        return $this->update($uuid, $updateData);
    }

    /**
     * Check if blob exists and is active
     *
     * Quick existence check for blob validation.
     *
     * @param string $uuid Blob UUID to check
     * @return bool True if blob exists and is active
     */
    public function exists(string $uuid): bool
    {
        return $this->count([
            'uuid' => $uuid,
            'status' => 'active'
        ]) > 0;
    }

    /**
     * Get recent blobs
     *
     * Retrieves recently uploaded blobs for dashboards and activity feeds.
     *
     * @param int $limit Number of recent blobs to retrieve
     * @param string|null $creatorUuid Optional creator filter
     * @return array Array of recent blob records
     */
    public function getRecent(int $limit = 10, ?string $creatorUuid = null): array
    {
        $conditions = ['status' => 'active'];

        if ($creatorUuid) {
            $conditions['created_by'] = $creatorUuid;
        }

        return $this->findWhere(
            $conditions,
            ['created_at' => 'desc'],
            $limit
        );
    }
}
