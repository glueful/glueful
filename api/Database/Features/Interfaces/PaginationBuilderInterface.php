<?php

declare(strict_types=1);

namespace Glueful\Database\Features\Interfaces;

/**
 * PaginationBuilder Interface
 *
 * Defines the contract for pagination functionality.
 * This interface ensures consistent pagination handling
 * across different implementations.
 */
interface PaginationBuilderInterface
{
    /**
     * Execute paginated query
     */
    public function paginate(int $page = 1, int $perPage = 10): array;

    /**
     * Execute paginated query with SQL and bindings
     */
    public function paginateQuery(string $sql, array $bindings, int $page = 1, int $perPage = 10): array;

    /**
     * Get total count for pagination
     */
    public function getTotalCount(string $sql, array $bindings): int;

    /**
     * Build optimized count query
     */
    public function buildCountQuery(string $originalQuery): string;

    /**
     * Apply limit and offset to query
     */
    public function applyPagination(string $sql, int $page, int $perPage): string;

    /**
     * Get pagination metadata
     */
    public function getPaginationMeta(int $total, int $page, int $perPage): array;
}
