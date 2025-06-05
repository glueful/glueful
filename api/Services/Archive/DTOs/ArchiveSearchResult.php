<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive Search Result
 *
 * Contains the results of searching through archived data
 * including matching records and performance metrics.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ArchiveSearchResult
{
    public function __construct(
        public readonly array $records,
        public readonly int $totalCount,
        public readonly array $archivesSearched,
        public readonly float $searchTime
    ) {
    }

    /**
     * Check if search returned any results
     */
    public function hasResults(): bool
    {
        return !empty($this->records);
    }

    /**
     * Get the number of records found
     */
    public function getRecordCount(): int
    {
        return count($this->records);
    }

    /**
     * Get the number of archives that were searched
     */
    public function getArchiveCount(): int
    {
        return count($this->archivesSearched);
    }
}
