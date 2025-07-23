<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Table Archive Statistics
 *
 * Contains statistics about a table's current state
 * and archival status for monitoring and automation.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class TableArchiveStats
{
    public function __construct(
        public readonly string $tableName,
        public readonly int $currentRowCount,
        public readonly int $currentSizeBytes,
        public readonly ?\DateTime $lastArchiveDate,
        public readonly ?\DateTime $nextArchiveDate,
        public readonly bool $needsArchive,
        public readonly int $thresholdRows = 100000,
        public readonly int $thresholdDays = 30
    ) {
    }

    /**
     * Get human-readable size
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->currentSizeBytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get days since last archive
     */
    public function getDaysSinceLastArchive(): ?int
    {
        if (!$this->lastArchiveDate) {
            return null;
        }

        return (new \DateTime())->diff($this->lastArchiveDate)->days;
    }

    /**
     * Check if table exceeds row threshold
     */
    public function exceedsRowThreshold(): bool
    {
        return $this->currentRowCount >= $this->thresholdRows;
    }

    /**
     * Check if table exceeds time threshold
     */
    public function exceedsTimeThreshold(): bool
    {
        $daysSince = $this->getDaysSinceLastArchive();
        return $daysSince !== null && $daysSince >= $this->thresholdDays;
    }
}
