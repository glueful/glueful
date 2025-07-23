<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Serialization\Attributes\{Groups, SerializedName};

/**
 * Pagination Metadata DTO
 */
class PaginationMetaDTO
{
    #[Groups(['response', 'pagination'])]
    #[SerializedName('current_page')]
    public int $currentPage;

    #[Groups(['response', 'pagination'])]
    #[SerializedName('per_page')]
    public int $perPage;

    #[Groups(['response', 'pagination'])]
    public int $total;

    #[Groups(['response', 'pagination'])]
    #[SerializedName('total_pages')]
    public int $totalPages;

    #[Groups(['response', 'pagination'])]
    #[SerializedName('has_more_pages')]
    public bool $hasMorePages;

    #[Groups(['response', 'pagination'])]
    #[SerializedName('from')]
    public int $from;

    #[Groups(['response', 'pagination'])]
    #[SerializedName('to')]
    public int $to;

    public function __construct(
        int $currentPage,
        int $perPage,
        int $total,
        int $totalPages
    ) {
        $this->currentPage = $currentPage;
        $this->perPage = $perPage;
        $this->total = $total;
        $this->totalPages = $totalPages;
        $this->hasMorePages = $currentPage < $totalPages;
        $this->from = ($currentPage - 1) * $perPage + 1;
        $this->to = min($currentPage * $perPage, $total);
    }
}
