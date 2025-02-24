<?php
declare(strict_types=1);

namespace Glueful\Http;

/**
 * Pagination Handler
 * 
 * Manages pagination calculations and state for API responses.
 * Handles limits, offsets, and page calculations with boundary checking.
 */
class Pagination
{
    /**
     * @var int Maximum number of items per page
     */
    private int $limit;

    /**
     * @var int Starting position for current page
     */
    private int $offset;

    /**
     * @var int Total number of available items
     */
    private int $total;

    /**
     * Constructor
     * 
     * @param int $limit Maximum items per page (1-100)
     * @param int $offset Starting position
     * @param int $total Total number of items
     */
    public function __construct(
        int $limit = 10,
        int $offset = 0,
        int $total = 0
    ) {
        $this->limit = max(1, min($limit, 100)); // Ensure limit is between 1-100
        $this->offset = max(0, $offset);
        $this->total = max(0, $total);
    }

    /**
     * Get maximum items per page
     * 
     * @return int Items per page limit
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get current offset
     * 
     * @return int Current page starting position
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Get total number of items
     * 
     * @return int Total available items
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Set total number of items
     * 
     * @param int $total New total count
     */
    public function setTotal(int $total): void
    {
        $this->total = max(0, $total);
    }

    /**
     * Calculate total number of pages
     * 
     * @return int Total pages based on limit and total items
     */
    public function getTotalPages(): int
    {
        return (int) ceil($this->total / $this->limit);
    }

    /**
     * Get current page number
     * 
     * @return int Current page (1-based)
     */
    public function getCurrentPage(): int
    {
        return (int) floor($this->offset / $this->limit) + 1;
    }

    /**
     * Check if next page exists
     * 
     * @return bool True if there are more pages after current
     */
    public function hasNextPage(): bool
    {
        return $this->getCurrentPage() < $this->getTotalPages();
    }

    /**
     * Check if previous page exists
     * 
     * @return bool True if there are pages before current
     */
    public function hasPreviousPage(): bool
    {
        return $this->getCurrentPage() > 1;
    }
}