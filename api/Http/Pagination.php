<?php
declare(strict_types=1);

namespace Glueful\Api\Http;

class Pagination
{
    private int $limit;
    private int $offset;
    private int $total;

    public function __construct(
        int $limit = 10,
        int $offset = 0,
        int $total = 0
    ) {
        $this->limit = max(1, min($limit, 100)); // Ensure limit is between 1-100
        $this->offset = max(0, $offset);
        $this->total = max(0, $total);
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = max(0, $total);
    }

    public function getTotalPages(): int
    {
        return (int) ceil($this->total / $this->limit);
    }

    public function getCurrentPage(): int
    {
        return (int) floor($this->offset / $this->limit) + 1;
    }

    public function hasNextPage(): bool
    {
        return $this->getCurrentPage() < $this->getTotalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->getCurrentPage() > 1;
    }
}