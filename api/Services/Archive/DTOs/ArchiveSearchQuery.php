<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive Search Query
 *
 * Defines search criteria for querying archived data.
 * Supports filtering by entity types, date ranges, and tables.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ArchiveSearchQuery
{
    public function __construct(
        public readonly ?string $userUuid = null,
        public readonly ?string $endpoint = null,
        public readonly ?string $action = null,
        public readonly ?string $ipAddress = null,
        public readonly ?\DateTime $startDate = null,
        public readonly ?\DateTime $endDate = null,
        public readonly array $tables = [],
        public readonly int $limit = 100,
        public readonly int $offset = 0
    ) {
    }

    /**
     * Create a search query for a specific user
     */
    public static function forUser(string $userUuid, ?\DateTime $startDate = null, ?\DateTime $endDate = null): self
    {
        return new self(userUuid: $userUuid, startDate: $startDate, endDate: $endDate);
    }

    /**
     * Create a search query for a specific endpoint
     */
    public static function forEndpoint(string $endpoint, ?\DateTime $startDate = null, ?\DateTime $endDate = null): self
    {
        return new self(endpoint: $endpoint, startDate: $startDate, endDate: $endDate);
    }

    /**
     * Create a search query for a specific action
     */
    public static function forAction(string $action, ?\DateTime $startDate = null, ?\DateTime $endDate = null): self
    {
        return new self(action: $action, startDate: $startDate, endDate: $endDate);
    }

    /**
     * Create a search query for a date range
     */
    public static function forDateRange(\DateTime $startDate, \DateTime $endDate): self
    {
        return new self(startDate: $startDate, endDate: $endDate);
    }
}
