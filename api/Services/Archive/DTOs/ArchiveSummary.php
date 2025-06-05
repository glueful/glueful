<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive System Summary
 *
 * Provides an overview of the entire archive system
 * including totals, breakdowns, and key metrics.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ArchiveSummary
{
    public function __construct(
        public readonly int $totalArchives,
        public readonly int $totalRecordsArchived,
        public readonly int $totalSizeBytes,
        public readonly array $tableBreakdown,
        public readonly ?\DateTime $oldestArchive,
        public readonly ?\DateTime $newestArchive
    ) {
    }

    /**
     * Get human-readable total size
     */
    public function getFormattedTotalSize(): string
    {
        $bytes = $this->totalSizeBytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get the time span of all archives
     */
    public function getArchiveTimeSpan(): ?int
    {
        if (!$this->oldestArchive || !$this->newestArchive) {
            return null;
        }

        return $this->newestArchive->diff($this->oldestArchive)->days;
    }

    /**
     * Get average records per archive
     */
    public function getAverageRecordsPerArchive(): float
    {
        if ($this->totalArchives === 0) {
            return 0;
        }

        return $this->totalRecordsArchived / $this->totalArchives;
    }

    /**
     * Get table with most archives
     */
    public function getMostArchivedTable(): ?string
    {
        if (empty($this->tableBreakdown)) {
            return null;
        }

        $maxCount = 0;
        $maxTable = null;

        foreach ($this->tableBreakdown as $table => $stats) {
            if ($stats['count'] > $maxCount) {
                $maxCount = $stats['count'];
                $maxTable = $table;
            }
        }

        return $maxTable;
    }
}
