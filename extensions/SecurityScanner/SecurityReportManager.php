<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Security Dashboard
 *
 * Provides centralized reporting for security scan results
 */
class SecurityReportManager
{
    /** @var array Dashboard configuration */
    private array $config;

    /**
     * Constructor
     *
     * @param array $config Dashboard configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Generate security report
     *
     * @param array $results Scan results
     * @return array Report summary
     */
    public function generateReport(array $results): array
    {
        $report = [
            'timestamp' => time(),
            'summary' => [
                'total_vulnerabilities' => 0,
                'risk_level' => 'low',
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0
            ],
            'scan_types' => []
        ];

        // Compile summary from all scan types
        foreach ($results as $scanType => $scanResult) {
            $report['scan_types'][$scanType] = [
                'vulnerabilities_found' => $scanResult['summary']['vulnerabilities_found'] ?? 0,
                'critical' => $scanResult['summary']['critical'] ?? 0,
                'high' => $scanResult['summary']['high'] ?? 0,
                'medium' => $scanResult['summary']['medium'] ?? 0,
                'low' => $scanResult['summary']['low'] ?? 0
            ];

            // Update totals
            $report['summary']['total_vulnerabilities'] += $report['scan_types'][$scanType]['vulnerabilities_found'];
            $report['summary']['critical'] += $report['scan_types'][$scanType]['critical'];
            $report['summary']['high'] += $report['scan_types'][$scanType]['high'];
            $report['summary']['medium'] += $report['scan_types'][$scanType]['medium'];
            $report['summary']['low'] += $report['scan_types'][$scanType]['low'];
        }

        // Calculate risk level
        $report['summary']['risk_level'] = $this->calculateRiskLevel($report['summary']);

        // Store report in database
        $this->storeReport($report);

        return $report;
    }

    /**
     * Calculate overall risk level
     *
     * @param array $summary Report summary
     * @return string Risk level (critical, high, medium, low)
     */
    private function calculateRiskLevel(array $summary): string
    {
        // Risk thresholds from configuration
        $thresholds = $this->config['risk_thresholds'] ?? [
            'critical' => 1,  // Any critical vulnerability = critical risk
            'high' => 5,      // 5+ high vulnerabilities = high risk
            'medium' => 10    // 10+ medium vulnerabilities = medium risk
        ];

        // Determine risk level
        if ($summary['critical'] >= $thresholds['critical']) {
            return 'critical';
        } elseif ($summary['high'] >= $thresholds['high']) {
            return 'high';
        } elseif ($summary['medium'] >= $thresholds['medium']) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Get security trend over time
     *
     * @param int $days Number of days to include (default: 30)
     * @return array Trend data
     */
    public function getTrend(int $days = 30): array
    {
        $trend = [
            'dates' => [],
            'vulnerabilities' => [],
            'by_severity' => [
                'critical' => [],
                'high' => [],
                'medium' => [],
                'low' => []
            ]
        ];

        try {
            $connection = new Connection();
            $db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Get statistics for each day
            $endDate = time();
            $startDate = $endDate - ($days * 24 * 60 * 60);

            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', $endDate - ($i * 24 * 60 * 60));
                $trend['dates'][] = $date;

                // Query vulnerabilities for this day
                $query = "
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                        SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
                        SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
                        SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low
                    FROM 
                        security_vulnerabilities
                    JOIN 
                        security_scans ON security_scans.id = security_vulnerabilities.scan_id
                    WHERE 
                        DATE(security_scans.timestamp) = ?
                ";

                $result = $db->rawQuery($query, [$date])[0];

                // Add to trend data
                $trend['vulnerabilities'][] = (int)($result['total'] ?? 0);
                $trend['by_severity']['critical'][] = (int)($result['critical'] ?? 0);
                $trend['by_severity']['high'][] = (int)($result['high'] ?? 0);
                $trend['by_severity']['medium'][] = (int)($result['medium'] ?? 0);
                $trend['by_severity']['low'][] = (int)($result['low'] ?? 0);
            }

            // Reverse arrays so they're in chronological order
            $trend['dates'] = array_reverse($trend['dates']);
            $trend['vulnerabilities'] = array_reverse($trend['vulnerabilities']);
            $trend['by_severity']['critical'] = array_reverse($trend['by_severity']['critical']);
            $trend['by_severity']['high'] = array_reverse($trend['by_severity']['high']);
            $trend['by_severity']['medium'] = array_reverse($trend['by_severity']['medium']);
            $trend['by_severity']['low'] = array_reverse($trend['by_severity']['low']);
        } catch (\Exception $e) {
            error_log("Failed to get security trend: " . $e->getMessage());
        }

        return $trend;
    }

    /**
     * Get remediation progress
     *
     * @return array Remediation statistics
     */
    public function getRemediationProgress(): array
    {
        $progress = [
            'total' => 0,
            'fixed' => 0,
            'in_progress' => 0,
            'new' => 0,
            'ignored' => 0,
            'by_severity' => [
                'critical' => ['total' => 0, 'fixed' => 0],
                'high' => ['total' => 0, 'fixed' => 0],
                'medium' => ['total' => 0, 'fixed' => 0],
                'low' => ['total' => 0, 'fixed' => 0]
            ]
        ];

        try {
            $connection = new Connection();
            $db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Get overall status counts
            $query = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'fixed' THEN 1 ELSE 0 END) as fixed,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new,
                    SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) as ignored
                FROM 
                    security_vulnerabilities
            ";

            $result = $db->rawQuery($query)[0];

            $progress['total'] = (int)($result['total'] ?? 0);
            $progress['fixed'] = (int)($result['fixed'] ?? 0);
            $progress['in_progress'] = (int)($result['in_progress'] ?? 0);
            $progress['new'] = (int)($result['new'] ?? 0);
            $progress['ignored'] = (int)($result['ignored'] ?? 0);

            // Get counts by severity
            $query = "
                SELECT 
                    severity,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'fixed' THEN 1 ELSE 0 END) as fixed
                FROM 
                    security_vulnerabilities
                GROUP BY
                    severity
            ";

            $results = $db->rawQuery($query);

            foreach ($results as $result) {
                $severity = $result['severity'];
                if (isset($progress['by_severity'][$severity])) {
                    $progress['by_severity'][$severity]['total'] = (int)$result['total'];
                    $progress['by_severity'][$severity]['fixed'] = (int)$result['fixed'];
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to get remediation progress: " . $e->getMessage());
        }

        return $progress;
    }

    /**
     * Update vulnerability status
     *
     * @param int $vulnerabilityId Vulnerability ID
     * @param string $status New status (new, in_progress, fixed, ignored)
     * @param string|null $comment Optional comment
     * @return bool Whether the update was successful
     */
    public function updateVulnerabilityStatus(int $vulnerabilityId, string $status, ?string $comment = null): bool
    {
        try {
            $connection = new Connection();
            $db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            $data = [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($comment !== null) {
                $data['comment'] = $comment;
            }

            $result = $db->update('security_vulnerabilities', $data, ['id' => $vulnerabilityId]);

            return $result > 0;
        } catch (\Exception $e) {
            error_log("Failed to update vulnerability status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store security report in database
     *
     * @param array $report Security report
     * @return void
     */
    private function storeReport(array $report): void
    {
        try {
            $connection = new Connection();
            $db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Insert report record
            $db->insert('security_reports', [
                'timestamp' => date('Y-m-d H:i:s', $report['timestamp']),
                'total_vulnerabilities' => $report['summary']['total_vulnerabilities'],
                'risk_level' => $report['summary']['risk_level'],
                'critical_count' => $report['summary']['critical'],
                'high_count' => $report['summary']['high'],
                'medium_count' => $report['summary']['medium'],
                'low_count' => $report['summary']['low']
            ]);
        } catch (\Exception $e) {
            error_log("Failed to store security report: " . $e->getMessage());
        }
    }
}
