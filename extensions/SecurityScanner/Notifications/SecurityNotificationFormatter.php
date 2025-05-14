<?php

declare(strict_types=1);

namespace Glueful\Extensions\SecurityScanner\Notifications;

use Glueful\Logging\LogManager;

/**
 * Security Notification Formatter
 *
 * Formats security notifications for display in different contexts.
 *
 * @package Glueful\Extensions\SecurityScanner\Notifications
 */
class SecurityNotificationFormatter
{
    /**
     * @var LogManager Logger instance
     */
    private LogManager $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new LogManager('security_formatter');
    }

    /**
     * Format a notification for sending
     *
     * @param array $data The notification data
     * @return array The formatted notification
     */
    public function format(array $data): array
    {
        $type = $data['type'] ?? 'unknown';
        $severity = $data['severity'] ?? 'medium';

        // Start with default formatting
        $formatted = [
            'title' => $this->getDefaultTitle($type, $severity),
            'content' => '',
            'severity' => $severity,
            'icon' => $this->getSeverityIcon($severity),
            'color' => $this->getSeverityColor($severity),
            'actions' => []
        ];

        // Format based on notification type
        switch ($type) {
            case 'vulnerability_detected':
                $formatted = $this->formatVulnerabilityDetected($data, $formatted);
                break;

            case 'scan_completed':
                $formatted = $this->formatScanCompleted($data, $formatted);
                break;

            case 'remediation_required':
                $formatted = $this->formatRemediationRequired($data, $formatted);
                break;

            case 'security_alert':
                $formatted = $this->formatSecurityAlert($data, $formatted);
                break;

            case 'dependency_vulnerability':
                $formatted = $this->formatDependencyVulnerability($data, $formatted);
                break;

            case 'code_vulnerability':
                $formatted = $this->formatCodeVulnerability($data, $formatted);
                break;

            case 'api_vulnerability':
                $formatted = $this->formatApiVulnerability($data, $formatted);
                break;

            default:
                // Use default formatting for unknown types
                $formatted['content'] = $data['message'] ?? 'Security notification';
        }

        return $formatted;
    }

    /**
     * Get the default notification title based on type and severity
     *
     * @param string $type The notification type
     * @param string $severity The notification severity
     * @return string The default title
     */
    private function getDefaultTitle(string $type, string $severity): string
    {
        $titles = [
            'vulnerability_detected' => 'Security Vulnerability Detected',
            'scan_completed' => 'Security Scan Completed',
            'remediation_required' => 'Security Remediation Required',
            'security_alert' => 'Security Alert',
            'dependency_vulnerability' => 'Dependency Vulnerability Detected',
            'code_vulnerability' => 'Code Vulnerability Detected',
            'api_vulnerability' => 'API Vulnerability Detected'
        ];

        $severityPrefix = '';
        if ($severity === 'critical' || $severity === 'high') {
            $severityPrefix = strtoupper($severity) . ': ';
        }

        return $severityPrefix . ($titles[$type] ?? 'Security Notification');
    }

    /**
     * Get an icon based on severity
     *
     * @param string $severity The notification severity
     * @return string The icon name
     */
    private function getSeverityIcon(string $severity): string
    {
        $icons = [
            'critical' => 'shield-exclamation',
            'high' => 'exclamation-triangle',
            'medium' => 'exclamation-circle',
            'low' => 'info-circle'
        ];

        return $icons[$severity] ?? 'info-circle';
    }

    /**
     * Get a color based on severity
     *
     * @param string $severity The notification severity
     * @return string The color code
     */
    private function getSeverityColor(string $severity): string
    {
        $colors = [
            'critical' => '#d32f2f', // Red
            'high' => '#f57c00',     // Orange
            'medium' => '#fbc02d',   // Yellow
            'low' => '#388e3c'       // Green
        ];

        return $colors[$severity] ?? '#2196f3'; // Default to blue
    }

    /**
     * Format a vulnerability detected notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatVulnerabilityDetected(array $data, array $formatted): array
    {
        $vulnerability = $data['vulnerability'] ?? [];

        // Create detailed content
        $content = '';

        if (isset($vulnerability['description'])) {
            $content .= $vulnerability['description'] . "\n\n";
        }

        if (isset($vulnerability['location'])) {
            $content .= "**Location**: " . $vulnerability['location'] . "\n";
        }

        if (isset($vulnerability['severity'])) {
            $content .= "**Severity**: " . strtoupper($vulnerability['severity']) . "\n";
        }

        if (isset($vulnerability['cve_id'])) {
            $content .= "**CVE ID**: " . $vulnerability['cve_id'] . "\n";
        }

        if (isset($vulnerability['recommendation'])) {
            $content .= "\n**Recommendation**:\n" . $vulnerability['recommendation'];
        }

        $formatted['content'] = $content;

        // Add actions
        $formatted['actions'] = [
            [
                'text' => 'View Details',
                'url' => '/security-dashboard/vulnerability/' . ($vulnerability['id'] ?? 'details')
            ],
            [
                'text' => 'Mark as Fixed',
                'url' => '/security-dashboard/vulnerability/' . ($vulnerability['id'] ?? 'details') . '/resolve'
            ]
        ];

        return $formatted;
    }

    /**
     * Format a scan completed notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatScanCompleted(array $data, array $formatted): array
    {
        $scanResults = $data['scan_results'] ?? [];
        $scanType = $data['scan_type'] ?? 'security';

        // Create content
        $content = "A $scanType scan has completed.\n\n";

        // Add summary if available
        if (isset($scanResults['summary'])) {
            $summary = $scanResults['summary'];
            $content .= "**Summary**:\n";

            if (isset($summary['total_scanned'])) {
                $content .= "- Total items scanned: " . $summary['total_scanned'] . "\n";
            }

            if (isset($summary['vulnerabilities_found'])) {
                $content .= "- Vulnerabilities found: " . $summary['vulnerabilities_found'] . "\n";
            }

            // Add severity breakdown
            if (
                isset($summary['critical']) || isset($summary['high']) ||
                isset($summary['medium']) || isset($summary['low'])
            ) {
                $content .= "- Severity breakdown:\n";
                $content .= "  - Critical: " . ($summary['critical'] ?? 0) . "\n";
                $content .= "  - High: " . ($summary['high'] ?? 0) . "\n";
                $content .= "  - Medium: " . ($summary['medium'] ?? 0) . "\n";
                $content .= "  - Low: " . ($summary['low'] ?? 0) . "\n";
            }
        }

        $formatted['content'] = $content;

        // Add actions
        $formatted['actions'] = [
            [
                'text' => 'View Report',
                'url' => '/security-dashboard/scans/' . ($scanResults['id'] ?? 'latest')
            ]
        ];

        return $formatted;
    }

    /**
     * Format a remediation required notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatRemediationRequired(array $data, array $formatted): array
    {
        $vulnerability = $data['vulnerability'] ?? [];
        $dueDate = $data['due_date'] ?? null;

        // Create content
        $content = "A security vulnerability requires your attention.\n\n";

        if (isset($vulnerability['title'])) {
            $content .= "**Issue**: " . $vulnerability['title'] . "\n\n";
        }

        if (isset($vulnerability['description'])) {
            $content .= $vulnerability['description'] . "\n\n";
        }

        if (isset($vulnerability['location'])) {
            $content .= "**Location**: " . $vulnerability['location'] . "\n";
        }

        if (isset($vulnerability['severity'])) {
            $content .= "**Severity**: " . strtoupper($vulnerability['severity']) . "\n";
        }

        if ($dueDate) {
            $content .= "**Due Date**: " . date('Y-m-d', strtotime($dueDate)) . "\n";
        }

        if (isset($vulnerability['recommendation'])) {
            $content .= "\n**Recommended Action**:\n" . $vulnerability['recommendation'];
        }

        $formatted['content'] = $content;

        // Add actions
        $formatted['actions'] = [
            [
                'text' => 'View Issue',
                'url' => '/security-dashboard/remediation/' . ($vulnerability['id'] ?? 'details')
            ],
            [
                'text' => 'Mark as Fixed',
                'url' => '/security-dashboard/remediation/' . ($vulnerability['id'] ?? 'details') . '/resolve'
            ]
        ];

        return $formatted;
    }

    /**
     * Format a security alert notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatSecurityAlert(array $data, array $formatted): array
    {
        // Create content
        $content = $data['message'] ?? "Security alert issued.";

        if (isset($data['details'])) {
            $content .= "\n\n" . $data['details'];
        }

        $formatted['content'] = $content;

        // Add actions if provided
        if (isset($data['action_url'])) {
            $formatted['actions'] = [
                [
                    'text' => $data['action_text'] ?? 'View Details',
                    'url' => $data['action_url']
                ]
            ];
        }

        return $formatted;
    }

    /**
     * Format a dependency vulnerability notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatDependencyVulnerability(array $data, array $formatted): array
    {
        $dependency = $data['dependency'] ?? [];

        // Create content
        $content = "A vulnerability was found in a dependency.\n\n";

        if (isset($dependency['name'])) {
            $content .= "**Package**: " . $dependency['name'] . "\n";
        }

        if (isset($dependency['version'])) {
            $content .= "**Version**: " . $dependency['version'] . "\n";
        }

        if (isset($dependency['description'])) {
            $content .= "\n" . $dependency['description'] . "\n";
        }

        if (isset($dependency['fixed_in'])) {
            $content .= "\n**Fixed in version**: " . $dependency['fixed_in'] . "\n";
        }

        if (isset($dependency['recommendation'])) {
            $content .= "\n**Recommended Action**:\n" . $dependency['recommendation'];
        } else {
            $content .= "\n**Recommended Action**:\nUpdate the package to the latest secure version.";
        }

        $formatted['content'] = $content;

        // Add actions
        $formatted['actions'] = [
            [
                'text' => 'View Vulnerability',
                'url' => '/security-dashboard/dependencies/' . ($dependency['id'] ?? 'details')
            ]
        ];

        return $formatted;
    }

    /**
     * Format a code vulnerability notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatCodeVulnerability(array $data, array $formatted): array
    {
        $vulnerability = $data['vulnerability'] ?? [];

        // Create content
        $content = "A vulnerability was found in your code.\n\n";

        if (isset($vulnerability['type'])) {
            $content .= "**Type**: " . $vulnerability['type'] . "\n";
        }

        if (isset($vulnerability['file'])) {
            $content .= "**File**: " . $vulnerability['file'] . "\n";
        }

        if (isset($vulnerability['line'])) {
            $content .= "**Line**: " . $vulnerability['line'] . "\n";
        }

        if (isset($vulnerability['description'])) {
            $content .= "\n" . $vulnerability['description'] . "\n";
        }

        if (isset($vulnerability['code_snippet'])) {
            $content .= "\n**Code**:\n```\n" . $vulnerability['code_snippet'] . "\n```\n";
        }

        if (isset($vulnerability['recommendation'])) {
            $content .= "\n**Recommended Action**:\n" . $vulnerability['recommendation'];
        }

        $formatted['content'] = $content;

        // Add actions
        $formatted['actions'] = [
            [
                'text' => 'View in Dashboard',
                'url' => '/security-dashboard/code/' . ($vulnerability['id'] ?? 'details')
            ]
        ];

        // Add direct link to file if available
        if (isset($vulnerability['file']) && isset($vulnerability['line'])) {
            $formatted['actions'][] = [
                'text' => 'Open in Editor',
                'url' => '/editor?file=' . urlencode($vulnerability['file']) . '&line=' . $vulnerability['line']
            ];
        }

        return $formatted;
    }

    /**
     * Format an API vulnerability notification
     *
     * @param array $data The notification data
     * @param array $formatted The initial formatting
     * @return array The formatted notification
     */
    private function formatApiVulnerability(array $data, array $formatted): array
    {
        $vulnerability = $data['vulnerability'] ?? [];

        // Create content
        $content = "A vulnerability was found in an API endpoint.\n\n";

        if (isset($vulnerability['endpoint'])) {
            $content .= "**Endpoint**: " . $vulnerability['endpoint'] . "\n";
        }

        if (isset($vulnerability['method'])) {
            $content .= "**HTTP Method**: " . $vulnerability['method'] . "\n";
        }

        if (isset($vulnerability['type'])) {
            $content .= "**Vulnerability Type**: " . $vulnerability['type'] . "\n";
        }

        if (isset($vulnerability['description'])) {
            $content .= "\n" . $vulnerability['description'] . "\n";
        }

        if (isset($vulnerability['recommendation'])) {
            $content .= "\n**Recommended Action**:\n" . $vulnerability['recommendation'];
        }

        $formatted['content'] = $content;

        // Add actions
        $formatted['actions'] = [
            [
                'text' => 'View Details',
                'url' => '/security-dashboard/api/' . ($vulnerability['id'] ?? 'details')
            ]
        ];

        return $formatted;
    }
}
