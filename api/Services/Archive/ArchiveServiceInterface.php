<?php

namespace Glueful\Services\Archive;

use Glueful\Services\Archive\DTOs\ArchiveResult;
use Glueful\Services\Archive\DTOs\ArchiveSearchQuery;
use Glueful\Services\Archive\DTOs\ArchiveSearchResult;
use Glueful\Services\Archive\DTOs\ArchiveRestoreOptions;
use Glueful\Services\Archive\DTOs\RestoreResult;
use Glueful\Services\Archive\DTOs\TableArchiveStats;
use Glueful\Services\Archive\DTOs\ArchiveSummary;

/**
 * Archive Service Interface
 *
 * Defines the contract for data archiving services.
 * Provides methods for archiving, searching, and managing
 * archived data with support for compression and encryption.
 *
 * @package Glueful\Services\Archive
 */
interface ArchiveServiceInterface
{
    /**
     * Archive table data older than the specified cutoff date
     *
     * @param string $table Table name to archive
     * @param \DateTime $cutoffDate Records older than this date will be archived
     * @return ArchiveResult Result of the archive operation
     */
    public function archiveTable(string $table, \DateTime $cutoffDate): ArchiveResult;

    /**
     * Search across archived data
     *
     * @param ArchiveSearchQuery $query Search criteria
     * @return ArchiveSearchResult Search results from archives
     */
    public function searchArchives(ArchiveSearchQuery $query): ArchiveSearchResult;

    /**
     * Restore data from an archive
     *
     * @param string $archiveUuid Archive identifier
     * @param ArchiveRestoreOptions|null $options Restore options
     * @return RestoreResult Result of the restore operation
     */
    public function restoreFromArchive(string $archiveUuid, ?ArchiveRestoreOptions $options = null): RestoreResult;

    /**
     * Verify archive integrity
     *
     * @param string $archiveUuid Archive identifier
     * @return bool True if archive is valid and uncorrupted
     */
    public function verifyArchive(string $archiveUuid): bool;

    /**
     * Delete an archive permanently
     *
     * @param string $archiveUuid Archive identifier
     * @return bool True if archive was successfully deleted
     */
    public function deleteArchive(string $archiveUuid): bool;

    /**
     * Get archival statistics for a table
     *
     * @param string $table Table name
     * @return TableArchiveStats|null Table statistics or null if not tracked
     */
    public function getTableStats(string $table): ?TableArchiveStats;

    /**
     * Track table growth for automatic archiving decisions
     *
     * @param string $table Table name to track
     * @return void
     */
    public function trackTableGrowth(string $table): void;

    /**
     * Get overall archive system summary
     *
     * @return ArchiveSummary Summary of all archive activity
     */
    public function getArchiveSummary(): ArchiveSummary;

    /**
     * Check which tables need archiving based on configured thresholds
     *
     * @return array Array of table names that need archiving
     */
    public function getTablesNeedingArchival(): array;

    /**
     * Get list of all archives for a specific table
     *
     * @param string $table Table name
     * @return array Array of archive metadata
     */
    public function getTableArchives(string $table): array;
}
