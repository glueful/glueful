<?php

declare(strict_types=1);

namespace Glueful\Database\Features;

use Glueful\Database\Features\Interfaces\PaginationBuilderInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;
use Glueful\Database\QueryLogger;

/**
 * PaginationBuilder
 *
 * Handles query pagination with optimized count queries and metadata.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class PaginationBuilder implements PaginationBuilderInterface
{
    protected QueryExecutorInterface $executor;
    protected QueryLogger $logger;

    public function __construct(QueryExecutorInterface $executor, QueryLogger $logger)
    {
        $this->executor = $executor;
        $this->logger = $logger;
    }

    /**
     * Execute paginated query
     */
    public function paginate(int $page = 1, int $perPage = 10): array
    {
        throw new \LogicException('This method requires the full SQL query and bindings. Use paginateQuery() instead.');
    }

    /**
     * Execute paginated query with SQL and bindings
     */
    public function paginateQuery(string $sql, array $bindings, int $page = 1, int $perPage = 10): array
    {
        $timerId = $this->logger->startTiming();

        $this->logger->logEvent("Executing paginated query", [
            'page' => $page,
            'per_page' => $perPage,
            'query' => substr($sql, 0, 100) . '...'
        ], 'debug');

        $offset = ($page - 1) * $perPage;

        // Apply pagination to the query
        $paginatedSql = $this->applyPagination($sql, $page, $perPage);

        // Execute paginated query
        $data = $this->executor->executeQuery($paginatedSql, array_merge($bindings, [$perPage, $offset]));

        // Get total count
        $total = $this->getTotalCount($sql, $bindings);

        $executionTime = $this->logger->endTiming($timerId);

        $meta = $this->getPaginationMeta($total, $page, $perPage);

        $this->logger->logEvent("Pagination complete", [
            'total_records' => $total,
            'total_pages' => $meta['last_page'],
            'page' => $page,
            'record_count' => count($data),
            'execution_time_ms' => $executionTime
        ], 'debug');

        return array_merge([
            'data' => $data,
        ], $meta, [
            'execution_time_ms' => $executionTime
        ]);
    }

    /**
     * Get total count for pagination
     */
    public function getTotalCount(string $sql, array $bindings): int
    {
        $countQuery = $this->buildCountQuery($sql);
        return $this->executor->executeCount($countQuery, $bindings);
    }

    /**
     * Build optimized count query
     */
    public function buildCountQuery(string $originalQuery): string
    {
        // Remove unnecessary parts that don't affect count
        $countQuery = preg_replace('/SELECT\s.*?\sFROM/is', 'SELECT COUNT(*) as total FROM', $originalQuery);
        $countQuery = preg_replace('/\sORDER BY\s.*$/is', '', $countQuery);
        $countQuery = preg_replace('/\sLIMIT\s.*$/is', '', $countQuery);

        // If there's a GROUP BY, we need to count differently
        if (stripos($countQuery, 'GROUP BY') !== false) {
            return "SELECT COUNT(*) as total FROM ({$originalQuery}) as count_table";
        }

        return $countQuery;
    }

    /**
     * Apply limit and offset to query
     */
    public function applyPagination(string $sql, int $page, int $perPage): string
    {
        // Remove existing LIMIT/OFFSET before adding a new one
        $paginatedQuery = preg_replace('/\sLIMIT\s\d+(\sOFFSET\s\d+)?/i', '', $sql);
        $paginatedQuery .= " LIMIT ? OFFSET ?";

        return $paginatedQuery;
    }

    /**
     * Get pagination metadata
     */
    public function getPaginationMeta(int $total, int $page, int $perPage): array
    {
        $lastPage = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $from = $total > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $total);

        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Validate pagination parameters
     */
    public function validatePaginationParams(int $page, int $perPage): void
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page number must be greater than 0');
        }

        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page count must be greater than 0');
        }

        if ($perPage > 1000) {
            throw new \InvalidArgumentException('Per page count cannot exceed 1000');
        }
    }

    /**
     * Calculate pagination bounds with validation
     */
    public function calculateBounds(int $page, int $perPage): array
    {
        $this->validatePaginationParams($page, $perPage);

        $offset = ($page - 1) * $perPage;

        return [
            'limit' => $perPage,
            'offset' => $offset
        ];
    }
}
