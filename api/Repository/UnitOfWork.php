<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\QueryBuilder;
use Glueful\Exceptions\DatabaseException;

/**
 * Unit of Work Implementation
 *
 * Manages database transactions and entity state changes.
 * Provides a centralized way to track changes and commit them atomically.
 *
 * @package Glueful\Repository
 */
class UnitOfWork
{
    private QueryBuilder $db;
    private array $newEntities = [];
    private array $dirtyEntities = [];
    private array $removedEntities = [];
    private bool $isCommitting = false;

    public function __construct(QueryBuilder $db)
    {
        $this->db = $db;
    }

    /**
     * Register a new entity to be inserted
     *
     * @param string $table Table name
     * @param array $data Entity data
     * @param string|null $key Optional key for tracking
     */
    public function registerNew(string $table, array $data, ?string $key = null): void
    {
        $key = $key ?? uniqid('new_', true);
        $this->newEntities[$key] = ['table' => $table, 'data' => $data];
    }

    /**
     * Register an entity to be updated
     *
     * @param string $table Table name
     * @param string $uuid Entity UUID
     * @param array $data Updated data
     * @param string|null $key Optional key for tracking
     */
    public function registerDirty(string $table, string $uuid, array $data, ?string $key = null): void
    {
        $key = $key ?? $uuid;
        $this->dirtyEntities[$key] = ['table' => $table, 'uuid' => $uuid, 'data' => $data];
    }

    /**
     * Register an entity to be deleted
     *
     * @param string $table Table name
     * @param string $uuid Entity UUID
     * @param string|null $key Optional key for tracking
     */
    public function registerRemoved(string $table, string $uuid, ?string $key = null): void
    {
        $key = $key ?? $uuid;
        $this->removedEntities[$key] = ['table' => $table, 'uuid' => $uuid];
    }

    /**
     * Commit all registered changes in a transaction
     *
     * @return array Results of all operations
     * @throws DatabaseException If commit fails
     */
    public function commit(): array
    {
        if ($this->isCommitting) {
            throw new DatabaseException('Unit of Work is already committing');
        }

        if ($this->isEmpty()) {
            return ['new' => [], 'updated' => [], 'removed' => []];
        }

        $this->isCommitting = true;

        try {
            return $this->db->transaction(function () {
                $results = [
                    'new' => $this->commitNewEntities(),
                    'updated' => $this->commitDirtyEntities(),
                    'removed' => $this->commitRemovedEntities()
                ];

                $this->clear();
                return $results;
            });
        } catch (\Exception $e) {
            throw new DatabaseException('Unit of Work commit failed: ' . $e->getMessage(), 0);
        } finally {
            $this->isCommitting = false;
        }
    }

    /**
     * Rollback all changes and clear the unit of work
     */
    public function rollback(): void
    {
        $this->clear();
        $this->isCommitting = false;
    }

    /**
     * Check if there are any registered changes
     */
    public function isEmpty(): bool
    {
        return empty($this->newEntities) && empty($this->dirtyEntities) && empty($this->removedEntities);
    }

    /**
     * Clear all registered entities
     */
    public function clear(): void
    {
        $this->newEntities = [];
        $this->dirtyEntities = [];
        $this->removedEntities = [];
    }

    /**
     * Get count of registered entities by type
     */
    public function getEntityCounts(): array
    {
        return [
            'new' => count($this->newEntities),
            'dirty' => count($this->dirtyEntities),
            'removed' => count($this->removedEntities)
        ];
    }

    /**
     * Commit new entities
     */
    private function commitNewEntities(): array
    {
        $results = [];

        foreach ($this->newEntities as $key => $entity) {
            $result = $this->db->insert($entity['table'], $entity['data']);
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Commit dirty entities
     */
    private function commitDirtyEntities(): array
    {
        $results = [];

        foreach ($this->dirtyEntities as $key => $entity) {
            $result = $this->db->update($entity['table'], $entity['data'], ['uuid' => $entity['uuid']]);
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Commit removed entities
     */
    private function commitRemovedEntities(): array
    {
        $results = [];

        foreach ($this->removedEntities as $key => $entity) {
            $result = $this->db->delete($entity['table'], ['uuid' => $entity['uuid']]);
            $results[$key] = $result;
        }

        return $results;
    }
}
