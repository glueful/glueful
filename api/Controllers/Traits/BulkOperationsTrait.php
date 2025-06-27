<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

use Glueful\Http\Response;
use Glueful\Auth\PasswordHasher;

/**
 * BulkOperationsTrait
 *
 * Provides secure bulk operations for multiple records.
 * Includes comprehensive permission checking, rate limiting, and error handling.
 *
 * Performance Impact: High (50-100x slower than single operations)
 * - Individual permission checks for each record
 * - Use for administrative operations, not public APIs
 *
 * Usage:
 * ```php
 * class AdminResourceController extends ResourceController
 * {
 *     use BulkOperationsTrait;
 *
 *     protected bool $enableBulkOperations = true;
 *
 *     // Override to customize limits
 *     protected function getBulkLimits(): array
 *     {
 *         return [
 *             'delete' => 50,  // Max 50 deletes per operation
 *             'update' => 25   // Max 25 updates per operation
 *         ];
 *     }
 * }
 * ```
 *
 * Routes: PUT /{resource}/bulk, DELETE /{resource}/bulk
 * Permissions: `resource.{table}.bulk_{operation}` or `resource.bulk_{operation}`
 *
 * @package Glueful\Controllers\Traits
 */
trait BulkOperationsTrait
{
    /**
     * Bulk delete resources
     *
     * @param array $params Route parameters
     * @param array $deleteData DELETE data containing UUIDs
     * @return mixed HTTP response
     */
    public function bulkDelete(array $params, array $deleteData)
    {
        if (!$this->enableBulkOperations) {
            return Response::error('Bulk operations are disabled', Response::HTTP_FORBIDDEN);
        }

        $table = $params['resource'];
        $uuids = $deleteData['uuids'] ?? [];

        // Validate bulk operation
        if (empty($uuids) || !is_array($uuids)) {
            return Response::error('No UUIDs provided for bulk delete', Response::HTTP_BAD_REQUEST);
        }

        // Apply table access control
        $this->applyTableAccessControl($table);

        // Check bulk operation permission
        if (!$this->can("resource.{$table}.bulk_delete")) {
            $this->requirePermission('resource.bulk_delete');
        }

        // Get bulk limits
        $limits = $this->getBulkLimits();
        $maxDeletes = $limits['delete'] ?? 100;

        // Apply strict rate limiting for bulk operations
        $this->rateLimitResource($table, 'bulk_delete', 5, 300); // 5 per 5 minutes

        // Require low-risk behavior for bulk delete operations
        $this->requireLowRiskBehavior();

        // Validate bulk operation limits
        if (count($uuids) > $maxDeletes) {
            return Response::error(
                "Cannot delete more than {$maxDeletes} records at once",
                Response::HTTP_BAD_REQUEST
            );
        }

        $repository = $this->repositoryFactory->getRepository($table);
        $deleted = 0;
        $failed = [];

        // Batch ownership validation for performance
        $validUuids = $this->validateBulkOwnership($table, $uuids);

        // Batch check if records exist using findMultiple
        $existingRecords = $repository->findMultiple($uuids);

        // Process each UUID
        foreach ($uuids as $uuid) {
            try {
                // Skip if ownership validation failed
                if (!in_array($uuid, $validUuids)) {
                    $failed[] = ['uuid' => $uuid, 'reason' => 'Access denied: ownership validation failed'];
                    continue;
                }

                // Check if record exists (using batched data)
                if (!isset($existingRecords[$uuid])) {
                    $failed[] = ['uuid' => $uuid, 'reason' => 'Record not found'];
                    continue;
                }

                // Perform deletion
                if ($repository->delete($uuid)) {
                    $deleted++;
                    // Log individual deletions
                    $this->logResourceAccess('bulk_delete_item', $table, $uuid);
                } else {
                    $failed[] = ['uuid' => $uuid, 'reason' => 'Delete operation failed'];
                }
            } catch (\Exception $e) {
                $failed[] = ['uuid' => $uuid, 'reason' => $e->getMessage()];
            }
        }

        // Invalidate cache after bulk deletion
        $this->invalidateTableCache($table);

        // Log the bulk operation
        $this->logResourceAccess('bulk_delete', $table, null);

        $result = [
            'deleted' => $deleted,
            'failed' => $failed,
            'total_requested' => count($uuids),
            'success' => $deleted > 0,
            'message' => "Bulk delete completed: {$deleted} deleted, " . count($failed) . " failed"
        ];

        return Response::success($result);
    }

    /**
     * Bulk update resources
     *
     * @param array $params Route parameters
     * @param array $updateData UPDATE data containing updates array
     * @return mixed HTTP response
     */
    public function bulkUpdate(array $params, array $updateData)
    {
        if (!$this->enableBulkOperations) {
            return Response::error('Bulk operations are disabled', Response::HTTP_FORBIDDEN);
        }

        $table = $params['resource'];
        $updates = $updateData['updates'] ?? [];

        // Validate bulk operation
        if (empty($updates) || !is_array($updates)) {
            return Response::error('No updates provided for bulk update', Response::HTTP_BAD_REQUEST);
        }

        // Apply table access control
        $this->applyTableAccessControl($table);

        // Check bulk operation permission
        if (!$this->can("resource.{$table}.bulk_update")) {
            $this->requirePermission('resource.bulk_update');
        }

        // Get bulk limits
        $limits = $this->getBulkLimits();
        $maxUpdates = $limits['update'] ?? 50;

        // Apply strict rate limiting for bulk operations
        $this->rateLimitResource($table, 'bulk_update', 3, 300); // 3 per 5 minutes

        // Require low-risk behavior for bulk update operations
        $this->requireLowRiskBehavior();

        // Validate bulk operation limits
        if (count($updates) > $maxUpdates) {
            return Response::error(
                "Cannot update more than {$maxUpdates} records at once",
                Response::HTTP_BAD_REQUEST
            );
        }

        $repository = $this->repositoryFactory->getRepository($table);
        $updated = 0;
        $failed = [];

        // Extract UUIDs for batch ownership validation
        $uuids = array_column($updates, 'uuid');
        $validUuids = $this->validateBulkOwnership($table, $uuids);

        // Batch check if records exist using findMultiple
        $existingRecords = $repository->findMultiple($uuids);

        // Process each update
        foreach ($updates as $update) {
            $uuid = $update['uuid'] ?? null;
            $data = $update['data'] ?? [];

            if (!$uuid || empty($data)) {
                $failed[] = ['uuid' => $uuid, 'reason' => 'Missing UUID or data'];
                continue;
            }

            try {
                // Skip if ownership validation failed
                if (!in_array($uuid, $validUuids)) {
                    $failed[] = ['uuid' => $uuid, 'reason' => 'Access denied: ownership validation failed'];
                    continue;
                }

                // Check if record exists (using batched data)
                if (!isset($existingRecords[$uuid])) {
                    $failed[] = ['uuid' => $uuid, 'reason' => 'Record not found'];
                    continue;
                }

                // Hash password if present
                $passwordHasher = new PasswordHasher();
                if (isset($data['password'])) {
                    $data['password'] = $passwordHasher->hash($data['password']);
                }

                // Perform update
                if ($repository->update($uuid, $data)) {
                    $updated++;
                    // Log individual updates
                    $this->logResourceAccess('bulk_update_item', $table, $uuid);
                } else {
                    $failed[] = ['uuid' => $uuid, 'reason' => 'Update operation failed'];
                }
            } catch (\Exception $e) {
                $failed[] = ['uuid' => $uuid, 'reason' => $e->getMessage()];
            }
        }

        // Invalidate cache after bulk update
        $this->invalidateTableCache($table);

        // Log the bulk operation
        $this->logResourceAccess('bulk_update', $table, null);

        $result = [
            'updated' => $updated,
            'failed' => $failed,
            'total_requested' => count($updates),
            'success' => $updated > 0,
            'message' => "Bulk update completed: {$updated} updated, " . count($failed) . " failed"
        ];

        return Response::success($result);
    }

    /**
     * Validate ownership for multiple records (batch operation for performance)
     */
    protected function validateBulkOwnership(string $table, array $uuids): array
    {
        // If ownership validation is disabled, allow all
        if (!$this->enableOwnershipValidation) {
            return $uuids;
        }

        // If table doesn't require ownership validation, allow all
        if (!in_array($table, ['profiles', 'blobs'])) {
            return $uuids;
        }

        // If user is admin, allow all
        if (!$this->currentUser || $this->can('admin.access')) {
            return $uuids;
        }

        $repository = $this->repositoryFactory->getRepository($table);
        $userUuid = $this->getCurrentUserUuid();
        $validUuids = [];

        // Batch fetch all records using findMultiple for better performance
        $records = $repository->findMultiple($uuids);

        foreach ($records as $uuid => $record) {
            $isOwned = false;

            switch ($table) {
                case 'profiles':
                    $isOwned = isset($record['user_uuid']) && $record['user_uuid'] === $userUuid;
                    break;
                case 'blobs':
                    $isOwned = isset($record['created_by']) && $record['created_by'] === $userUuid;
                    break;
            }

            if ($isOwned) {
                $validUuids[] = $uuid;
            }
        }

        return $validUuids;
    }

    /**
     * Get bulk operation limits
     * Override this method to customize limits
     */
    protected function getBulkLimits(): array
    {
        // Allow configuration override
        $configLimits = config('resource.bulk_limits', []);

        if (!empty($configLimits)) {
            return $configLimits;
        }

        // Conservative defaults
        return [
            'delete' => 100,
            'update' => 50
        ];
    }
}
