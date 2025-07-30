<?php

declare(strict_types=1);

namespace Glueful\Repository\Concerns;

use Glueful\Database\Query\Interfaces\QueryBuilderInterface;

/**
 * Query Filter Trait
 *
 * Provides reusable filter processing logic for repository queries.
 * Eliminates code duplication by centralizing filter application logic.
 *
 * Supports various operators:
 * - gte: Greater than or equal
 * - lte: Less than or equal
 * - gt: Greater than
 * - lt: Less than
 * - like: Pattern matching
 * - in: Value in array
 *
 * @package Glueful\Repository\Concerns
 */
trait QueryFilterTrait
{
    /**
     * Apply filters to a query builder instance
     *
     * This method processes an array of filters and applies them to the provided
     * QueryBuilder instance. Supports both simple value filters and complex
     * operator-based filters.
     *
     * @param QueryBuilderInterface $query The query builder instance to apply filters to
     * @param array $filters Associative array of filters to apply
     * @return void
     *
     * @example
     * ```php
     * $filters = [
     *     'status' => 'active',                    // Simple equality
     *     'created_at' => ['gte' => '2024-01-01'], // Greater than or equal
     *     'tags' => ['in' => ['tag1', 'tag2']],    // Value in array
     *     'title' => ['like' => 'search term']     // Pattern matching
     * ];
     * $this->applyFilters($query, $filters);
     * ```
     */
    protected function applyFilters(QueryBuilderInterface $query, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // Handle operator-based filters
                foreach ($value as $operator => $val) {
                    $this->applyOperatorFilter($query, $field, $operator, $val);
                }
            } else {
                // Handle simple equality filters
                $query->where([$field => $value]);
            }
        }
    }

    /**
     * Apply a specific operator filter to the query
     *
     * @param QueryBuilderInterface $query The query builder instance
     * @param string $field The field name to filter on
     * @param string $operator The operator to apply
     * @param mixed $value The value to filter with
     * @return void
     */
    private function applyOperatorFilter(QueryBuilderInterface $query, string $field, string $operator, $value): void
    {
        switch ($operator) {
            case 'gte':
                $query->where($field, '>=', $value);
                break;
            case 'lte':
                $query->where($field, '<=', $value);
                break;
            case 'gt':
                $query->where($field, '>', $value);
                break;
            case 'lt':
                $query->where($field, '<', $value);
                break;
            case 'like':
                $query->where($field, 'LIKE', "%{$value}%");
                break;
            case 'in':
                if (is_array($value) && !empty($value)) {
                    $query->whereIn($field, $value);
                }
                break;
            case 'not_in':
                if (is_array($value) && !empty($value)) {
                    $query->whereNotIn($field, $value);
                }
                break;
            case 'ne':
            case 'not_equal':
                $query->where($field, '!=', $value);
                break;
            case 'null':
                $query->whereNull($field);
                break;
            case 'not_null':
                $query->whereNotNull($field);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($field, $value[0], $value[1]);
                }
                break;
            default:
                // For unknown operators, treat as equality
                $query->where([$field => $value]);
                break;
        }
    }

    /**
     * Apply notifiable-specific filters (for notification repositories)
     *
     * Common pattern for filtering by notifiable type and ID with optional
     * read status filtering.
     *
     * @param QueryBuilderInterface $query The query builder instance
     * @param string $notifiableType The notifiable type to filter by
     * @param string $notifiableId The notifiable ID to filter by
     * @param bool $onlyUnread Whether to filter only unread notifications
     * @return void
     */
    protected function applyNotifiableFilters(
        QueryBuilderInterface $query,
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false
    ): void {
        $query->where([
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId
        ]);

        if ($onlyUnread) {
            $query->whereNull('read_at');
        }
    }
}
