<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Serialization\Attributes\{Groups, SerializedName, DateFormat};

/**
 * Paginated Response DTO
 *
 * Standard DTO for paginated API responses with metadata about pagination
 * and flexible content serialization based on groups.
 */
class PaginatedResponseDTO
{
    #[Groups(['response', 'pagination'])]
    public array $data = [];

    #[Groups(['response', 'pagination'])]
    public PaginationMetaDTO $pagination;

    #[Groups(['response', 'meta'])]
    public ?array $meta = null;

    #[Groups(['response', 'links'])]
    public ?array $links = null;

    #[Groups(['response', 'debug'])]
    #[SerializedName('request_id')]
    public ?string $requestId = null;

    #[Groups(['response', 'debug'])]
    #[SerializedName('execution_time')]
    public ?float $executionTime = null;

    #[Groups(['response', 'debug'])]
    #[SerializedName('memory_usage')]
    public ?string $memoryUsage = null;

    #[Groups(['response'])]
    #[SerializedName('generated_at')]
    #[DateFormat('c')]
    public \DateTime $generatedAt;

    public function __construct(
        array $data = [],
        int $currentPage = 1,
        int $perPage = 20,
        int $total = 0,
        ?int $totalPages = null
    ) {
        $this->data = $data;
        $this->pagination = new PaginationMetaDTO(
            $currentPage,
            $perPage,
            $total,
            $totalPages ?? (int) ceil($total / $perPage)
        );
        $this->generatedAt = new \DateTime();
    }

    /**
     * Create paginated response from array data
     */
    public static function create(
        array $data,
        int $currentPage,
        int $perPage,
        int $total,
        ?array $meta = null
    ): self {
        $response = new self($data, $currentPage, $perPage, $total);
        $response->meta = $meta;
        return $response;
    }

    /**
     * Add pagination links
     */
    public function withLinks(string $baseUrl, array $params = []): self
    {
        $pagination = $this->pagination;
        $queryParams = array_merge($params, ['per_page' => $pagination->perPage]);

        $this->links = [
            'first' => $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => 1])),
            'last' => $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $pagination->totalPages])),
            'prev' => $pagination->currentPage > 1
                ? $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $pagination->currentPage - 1]))
                : null,
            'next' => $pagination->currentPage < $pagination->totalPages
                ? $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $pagination->currentPage + 1]))
                : null,
        ];

        return $this;
    }

    /**
     * Add metadata
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta ?? [], $meta);
        return $this;
    }

    /**
     * Add debug information
     */
    public function withDebugInfo(string $requestId, float $executionTime, int $memoryUsage): self
    {
        $this->requestId = $requestId;
        $this->executionTime = $executionTime;
        $this->memoryUsage = $this->formatBytes($memoryUsage);
        return $this;
    }

    /**
     * Build URL with query parameters
     */
    private function buildUrl(string $baseUrl, array $params): string
    {
        $query = http_build_query($params);
        return $baseUrl . ($query ? '?' . $query : '');
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(log($bytes, 1024));
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Check if there are more pages
     */
    public function hasMorePages(): bool
    {
        return $this->pagination->currentPage < $this->pagination->totalPages;
    }

    /**
     * Check if this is the first page
     */
    public function isFirstPage(): bool
    {
        return $this->pagination->currentPage === 1;
    }

    /**
     * Check if this is the last page
     */
    public function isLastPage(): bool
    {
        return $this->pagination->currentPage >= $this->pagination->totalPages;
    }

    /**
     * Get total count
     */
    public function getTotalCount(): int
    {
        return $this->pagination->total;
    }

    /**
     * Get current page count
     */
    public function getCurrentPageCount(): int
    {
        return count($this->data);
    }
}
