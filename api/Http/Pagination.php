<?php
declare(strict_types=1);

namespace Mapi\Api\Http;

class Pagination {
    private int $page;
    private int $perPage;
    private ?int $total;
    private array $items;

    public function __construct(
        int $page = 1,
        int $perPage = 15,
        ?int $total = null,
        array $items = []
    ) {
        $this->page = max(1, $page);
        $this->perPage = max(1, $perPage);
        $this->total = $total;
        $this->items = $items;
    }

    public function getOffset(): int {
        return ($this->page - 1) * $this->perPage;
    }

    public function getLimit(): int {
        return $this->perPage;
    }

    public function getTotalPages(): ?int {
        return $this->total ? (int) ceil($this->total / $this->perPage) : null;
    }

    public function toArray(): array {
        return [
            'current_page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->getTotalPages(),
            'items' => $this->items,
            'has_more' => $this->total ? ($this->page * $this->perPage) < $this->total : null
        ];
    }

    public static function fromRequest(?int $page = null, ?int $perPage = null): self {
        return new self(
            $page ?? (int) ($_GET['page'] ?? 1),
            $perPage ?? (int) ($_GET['per_page'] ?? 15)
        );
    }
}
