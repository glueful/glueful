<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Dashboard;

use Glueful\Logging\LogManager;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Security Dashboard
 *
 * Provides UI and data for viewing security scan results,
 * vulnerabilities, and recommended actions.
 *
 * @package Glueful\Extensions\SecurityScanner\Dashboard
 */
class SecurityDashboard
{
    /**
     * @var array Dashboard configuration
     */
    private array $config;

    /**
     * @var LogManager Logger instance
     */
    private LogManager $logger;

    /**
     * @var QueryBuilder|null Database query builder
     */
    private ?QueryBuilder $queryBuilder = null;

    /**
     * Constructor
     *
     * @param array $config Dashboard configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'results_per_page' => 20,
            'default_sort' => 'created_at',
            'default_sort_direction' => 'desc',
        ], $config);

        $this->logger = new LogManager('security_dashboard');

        try {
            $connection = new Connection();
            $this->queryBuilder = new QueryBuilder(
                $connection->getPDO(),
                $connection->getDriver()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize database connection: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Get recent security vulnerabilities
     *
     * @param int $limit The maximum number of results to return
     * @param string $severity Filter by severity (optional)
     * @return array The list of vulnerabilities
     */
    public function getRecentVulnerabilities(int $limit = 10, ?string $severity = null): array
    {
        if (!$this->queryBuilder) {
            return [];
        }

        try {
            $query = $this->queryBuilder->select('vulnerabilities', [
                'uuid', 'type', 'severity', 'title', 'location', 'description',
                'created_at', 'status'
            ])
                ->orderBy(['created_at' => 'DESC'])
                ->limit($limit);

            if ($severity !== null) {
                $query->where(['severity' => $severity]);
            }

            return $query->get() ?: [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch recent vulnerabilities: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return [];
        }
    }

    /**
     * Get vulnerability statistics
     *
     * @return array The vulnerability statistics
     */
    public function getVulnerabilityStats(): array
    {
        if (!$this->queryBuilder) {
            return [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'total' => 0
            ];
        }

        try {
            $stats = [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'total' => 0
            ];

            // Get counts by severity
            $severityCounts = $this->queryBuilder
                ->select('vulnerabilities', ['severity', 'COUNT(*) as count'])
                ->where(['status !=' => 'resolved'])
                ->groupBy(['severity'])
                ->get();

            if ($severityCounts) {
                foreach ($severityCounts as $row) {
                    $severity = strtolower($row['severity']);
                    if (isset($stats[$severity])) {
                        $stats[$severity] = (int)$row['count'];
                        $stats['total'] += (int)$row['count'];
                    }
                }
            }

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch vulnerability statistics: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'total' => 0
            ];
        }
    }

    /**
     * Get detailed information about a specific vulnerability
     *
     * @param string $uuid The UUID of the vulnerability
     * @return array|null The vulnerability details or null if not found
     */
    public function getVulnerabilityDetails(string $uuid): ?array
    {
        if (!$this->queryBuilder) {
            return null;
        }

        try {
            $vulnerability = $this->queryBuilder
                ->select('vulnerabilities', ['*'])
                ->where(['uuid' => $uuid])
                ->first();

            if (!$vulnerability) {
                return null;
            }

            // Get related remediation steps if available
            $remediation = $this->queryBuilder
                ->select('vulnerability_remediations', ['*'])
                ->where(['vulnerability_uuid' => $uuid])
                ->first();

            // Get related scan information
            $scan = $this->queryBuilder
                ->select('security_scans', ['uuid', 'type', 'started_at', 'completed_at', 'status'])
                ->where(['uuid' => $vulnerability['scan_uuid'] ?? ''])
                ->first();

            return [
                'details' => $vulnerability,
                'remediation' => $remediation ?: [],
                'scan' => $scan ?: []
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch vulnerability details: ' . $e->getMessage(), [
                'exception' => $e,
                'uuid' => $uuid
            ]);
            return null;
        }
    }

    /**
     * Get recent security scans
     *
     * @param int $limit The maximum number of results to return
     * @return array The list of recent scans
     */
    public function getRecentScans(int $limit = 5): array
    {
        if (!$this->queryBuilder) {
            return [];
        }

        try {
            return $this->queryBuilder
                ->select('security_scans', ['uuid', 'type', 'started_at', 'completed_at', 'status', 'issues_found'])
                ->orderBy(['started_at' => 'DESC'])
                ->limit($limit)
                ->get() ?: [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch recent scans: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return [];
        }
    }

    /**
     * Update the status of a vulnerability
     *
     * @param string $uuid The UUID of the vulnerability
     * @param string $status The new status (open, in_review, false_positive, resolved)
     * @param string|null $comment Optional comment about the status change
     * @return bool Whether the update was successful
     */
    public function updateVulnerabilityStatus(string $uuid, string $status, ?string $comment = null): bool
    {
        if (!$this->queryBuilder) {
            return false;
        }

        $validStatuses = ['open', 'in_review', 'false_positive', 'resolved'];
        if (!in_array($status, $validStatuses)) {
            $this->logger->warning('Invalid vulnerability status', [
                'uuid' => $uuid,
                'status' => $status,
                'valid_statuses' => $validStatuses
            ]);
            return false;
        }

        try {
            $data = [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($comment !== null) {
                $data['status_comment'] = $comment;
            }

            return (bool) $this->queryBuilder
                ->update('vulnerabilities', $data, ['uuid' => $uuid]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update vulnerability status: ' . $e->getMessage(), [
                'exception' => $e,
                'uuid' => $uuid,
                'status' => $status
            ]);
            return false;
        }
    }

    /**
     * Generate a security report for download
     *
     * @param string $format The report format (pdf, csv, json)
     * @param array $filters Filter criteria for the report
     * @return string|null The path to the generated report or null if generation failed
     */
    public function generateReport(string $format = 'pdf', array $filters = []): ?string
    {
        // This is a stub implementation that should be expanded based on reporting needs
        $this->logger->info('Security report generation requested', [
            'format' => $format,
            'filters' => $filters
        ]);

        return null; // Implementation needed
    }
}
