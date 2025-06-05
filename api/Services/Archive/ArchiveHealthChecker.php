<?php

namespace Glueful\Services\Archive;

use Glueful\Database\QueryBuilder;
use Glueful\Services\Archive\DTOs\HealthCheckResult;

/**
 * Archive Health Checker
 *
 * Monitors the health and integrity of the archive system including:
 * - File system integrity checks
 * - Storage usage monitoring
 * - Archive corruption detection
 * - Missing file detection
 * - Performance metrics
 *
 * @package Glueful\Services\Archive
 */
class ArchiveHealthChecker
{
    private string $archivePath;
    private array $config;

    public function __construct(
        private QueryBuilder $queryBuilder,
        array $config = []
    ) {
        $this->config = array_merge([
            'storage_path' => config('archive.storage.path'),
            'disk_space_threshold' => config('archive.monitoring.disk_space_threshold_percent', 85),
            'enable_health_checks' => config('archive.monitoring.enable_health_checks', true),
            'max_failed_archives' => config('archive.monitoring.max_failed_archives', 5),
        ], $config);

        $this->archivePath = $this->config['storage_path'];
    }

    /**
     * Perform comprehensive health check
     *
     * @return HealthCheckResult
     */
    public function performHealthCheck(): HealthCheckResult
    {
        if (!$this->config['enable_health_checks']) {
            return new HealthCheckResult(true, [], ['health_checks' => 'disabled']);
        }

        $issues = [];
        $warnings = [];
        $metrics = [];

        try {
            // Check file system integrity
            $corruptedArchives = $this->findCorruptedArchives();
            if (!empty($corruptedArchives)) {
                $issues[] = "Corrupted archives found: " . count($corruptedArchives) . " archives";
                $metrics['corrupted_archives'] = $corruptedArchives;
            }

            // Check storage usage
            $storageMetrics = $this->checkStorageUsage();
            $metrics['storage'] = $storageMetrics;

            if ($storageMetrics['usage_percent'] > $this->config['disk_space_threshold']) {
                $issues[] = sprintf(
                    "Archive storage is %.1f%% full (threshold: %d%%)",
                    $storageMetrics['usage_percent'],
                    $this->config['disk_space_threshold']
                );
            } elseif ($storageMetrics['usage_percent'] > 70) {
                $warnings[] = sprintf(
                    "Archive storage is %.1f%% full",
                    $storageMetrics['usage_percent']
                );
            }

            // Check missing archives
            $missingArchives = $this->findMissingArchives();
            if (!empty($missingArchives)) {
                $issues[] = "Missing archive files: " . count($missingArchives) . " files";
                $metrics['missing_archives'] = $missingArchives;
            }

            // Check failed archives
            $failedArchives = $this->checkFailedArchives();
            if ($failedArchives > $this->config['max_failed_archives']) {
                $issues[] = "Too many failed archives: {$failedArchives} (max: {$this->config['max_failed_archives']})";
            }
            $metrics['failed_archives'] = $failedArchives;

            // Check archive age distribution
            $ageDistribution = $this->checkArchiveAgeDistribution();
            $metrics['age_distribution'] = $ageDistribution;

            // Check for stale archives
            $staleArchives = $this->findStaleArchives();
            if (!empty($staleArchives)) {
                $warnings[] = "Found {$staleArchives} archives older than retention policy";
            }
        } catch (\Exception $e) {
            $issues[] = "Health check error: " . $e->getMessage();
        }

        return new HealthCheckResult(
            healthy: empty($issues),
            issues: $issues,
            warnings: $warnings,
            metrics: $metrics
        );
    }

    /**
     * Find corrupted archives by verifying checksums
     *
     * @return array List of corrupted archive UUIDs
     */
    private function findCorruptedArchives(): array
    {
        $corrupted = [];

        try {
            $archives = $this->queryBuilder
                ->select('archive_registry', ['uuid', 'file_path', 'checksum_sha256'])
                ->where(['status', '!=', 'deleted'])
                ->limit(100) // Check latest 100 archives
                ->orderBy(['created_at' => 'DESC'])
                ->get();

            foreach ($archives as $archive) {
                if (!file_exists($archive['file_path'])) {
                    continue; // Will be caught by missing archives check
                }

                $currentChecksum = hash_file('sha256', $archive['file_path']);
                if ($currentChecksum !== $archive['checksum_sha256']) {
                    $corrupted[] = $archive['uuid'];

                    // Update status in database
                    $this->queryBuilder->update(
                        'archive_registry',
                        ['status' => 'corrupted'],
                        ['uuid' => $archive['uuid']]
                    );
                }
            }
        } catch (\Exception $e) {
            error_log("Error checking archive corruption: " . $e->getMessage());
        }

        return $corrupted;
    }

    /**
     * Check storage usage
     *
     * @return array Storage metrics
     */
    private function checkStorageUsage(): array
    {
        $totalSpace = disk_total_space($this->archivePath);
        $freeSpace = disk_free_space($this->archivePath);
        $usedSpace = $totalSpace - $freeSpace;

        // Get archive-specific usage
        $archiveSize = 0;
        try {
            $result = $this->queryBuilder
                ->select('archive_registry', [
                    $this->queryBuilder->raw('SUM(file_size) as total_size')
                ])
                ->where(['status', '!=', 'deleted'])
                ->first();

            $archiveSize = $result['total_size'] ?? 0;
        } catch (\Exception $e) {
            error_log("Error calculating archive size: " . $e->getMessage());
        }

        return [
            'total_space' => $totalSpace,
            'used_space' => $usedSpace,
            'free_space' => $freeSpace,
            'archive_size' => $archiveSize,
            'usage_percent' => ($usedSpace / $totalSpace) * 100,
            'archive_percent' => ($archiveSize / $totalSpace) * 100,
        ];
    }

    /**
     * Find missing archive files
     *
     * @return array List of missing archive UUIDs
     */
    private function findMissingArchives(): array
    {
        $missing = [];

        try {
            $archives = $this->queryBuilder
                ->select('archive_registry', ['uuid', 'file_path'])
                ->where(['status', '!=', 'deleted'])
                ->get();

            foreach ($archives as $archive) {
                if (!file_exists($archive['file_path'])) {
                    $missing[] = $archive['uuid'];

                    // Update status in database
                    $this->queryBuilder->update(
                        'archive_registry',
                        ['status' => 'missing'],
                        ['uuid' => $archive['uuid']]
                    );
                }
            }
        } catch (\Exception $e) {
            error_log("Error checking missing archives: " . $e->getMessage());
        }

        return $missing;
    }

    /**
     * Check number of failed archives
     *
     * @return int Number of failed archives
     */
    private function checkFailedArchives(): int
    {
        try {
            return $this->queryBuilder->count('archive_registry', ['status' => 'failed']);
        } catch (\Exception $e) {
            error_log("Error counting failed archives: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check archive age distribution
     *
     * @return array Age distribution metrics
     */
    private function checkArchiveAgeDistribution(): array
    {
        try {
            // Get counts for different time periods separately to avoid database-specific date functions
            $lastWeek = $this->queryBuilder->count('archive_registry', [
                ['created_at', '>', date('Y-m-d H:i:s', strtotime('-7 days'))],
                ['status', '!=', 'deleted']
            ]);

            $lastMonth = $this->queryBuilder->count('archive_registry', [
                ['created_at', '>', date('Y-m-d H:i:s', strtotime('-30 days'))],
                ['status', '!=', 'deleted']
            ]);

            $lastQuarter = $this->queryBuilder->count('archive_registry', [
                ['created_at', '>', date('Y-m-d H:i:s', strtotime('-90 days'))],
                ['status', '!=', 'deleted']
            ]);

            $lastYear = $this->queryBuilder->count('archive_registry', [
                ['created_at', '>', date('Y-m-d H:i:s', strtotime('-365 days'))],
                ['status', '!=', 'deleted']
            ]);

            $total = $this->queryBuilder->count('archive_registry', [
                ['status', '!=', 'deleted']
            ]);

            return [
                'last_week' => $lastWeek,
                'last_month' => $lastMonth,
                'last_quarter' => $lastQuarter,
                'last_year' => $lastYear,
                'total' => $total
            ];
        } catch (\Exception $e) {
            error_log("Error checking archive age distribution: " . $e->getMessage());
            return [
                'last_week' => 0,
                'last_month' => 0,
                'last_quarter' => 0,
                'last_year' => 0,
                'total' => 0
            ];
        }
    }

    /**
     * Find archives that exceed retention policies
     *
     * @return int Number of stale archives
     */
    private function findStaleArchives(): int
    {
        try {
            $retentionPolicies = config('archive.retention_policies', []);
            $staleCount = 0;

            foreach ($retentionPolicies as $table => $policy) {
                $complianceYears = $policy['compliance_period_years'] ?? 7;
                $cutoffDate = date('Y-m-d', strtotime("-{$complianceYears} years"));

                $count = $this->queryBuilder->count('archive_registry', [
                    'table_name' => $table,
                    ['created_at', '<', $cutoffDate],
                    'status' => 'completed'
                ]);

                $staleCount += $count;
            }

            return $staleCount;
        } catch (\Exception $e) {
            error_log("Error checking stale archives: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get detailed health report
     *
     * @return array Detailed health metrics
     */
    public function getDetailedHealthReport(): array
    {
        $healthCheck = $this->performHealthCheck();

        return [
            'healthy' => $healthCheck->healthy,
            'timestamp' => date('Y-m-d H:i:s'),
            'issues' => $healthCheck->issues,
            'warnings' => $healthCheck->warnings,
            'metrics' => $healthCheck->metrics,
            'recommendations' => $this->generateRecommendations($healthCheck)
        ];
    }

    /**
     * Generate recommendations based on health check
     *
     * @param HealthCheckResult $healthCheck
     * @return array List of recommendations
     */
    private function generateRecommendations(HealthCheckResult $healthCheck): array
    {
        $recommendations = [];

        if (
            isset($healthCheck->metrics['storage']['usage_percent']) &&
            $healthCheck->metrics['storage']['usage_percent'] > 70
        ) {
            $recommendations[] = "Consider increasing storage capacity or implementing " .
                "more aggressive archival policies";
        }

        if (
            isset($healthCheck->metrics['corrupted_archives']) &&
            !empty($healthCheck->metrics['corrupted_archives'])
        ) {
            $recommendations[] = "Run integrity verification on all archives and consider re-archiving corrupted data";
        }

        if (
            isset($healthCheck->metrics['failed_archives']) &&
            $healthCheck->metrics['failed_archives'] > 0
        ) {
            $recommendations[] = "Review and retry failed archive operations";
        }

        if (
            isset($healthCheck->metrics['age_distribution']['total']) &&
            $healthCheck->metrics['age_distribution']['last_week'] == 0
        ) {
            $recommendations[] = "No archives created in the last week - verify automatic archiving is working";
        }

        return $recommendations;
    }
}
