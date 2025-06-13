<?php

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;
use Glueful\Logging\AuditLogger;

/**
 * Process Audit Log Job
 *
 * Handles asynchronous processing of audit log entries.
 * This job is designed to reduce database load by processing
 * audit logs in the background rather than during the main request.
 *
 * Features:
 * - Asynchronous audit log processing
 * - Fallback logging for failed attempts
 * - Integration with existing AuditLogger
 * - Error handling and recovery
 *
 * Usage:
 * ```php
 * $job = new ProcessAuditLog([
 *     'category' => 'user',
 *     'action' => 'login',
 *     'severity' => 'info',
 *     'context' => ['user_id' => 123]
 * ]);
 * Queue::push($job);
 * ```
 *
 * @package Glueful\Queue\Jobs
 */
class ProcessAuditLog extends Job
{
    /**
     * Process the audit log entry
     *
     * @return void
     * @throws \Exception If audit logging fails
     */
    public function handle(): void
    {
        $data = $this->getData();

        // Validate required data
        if (!isset($data['category'], $data['action'], $data['severity'])) {
            throw new \Exception('Missing required audit log data: category, action, or severity');
        }

        // Get AuditLogger instance
        $auditLogger = AuditLogger::getInstance();

        // Process the audit log
        $auditLogger->audit(
            $data['category'],
            $data['action'],
            $data['severity'],
            $data['context'] ?? []
        );
    }

    /**
     * Handle job failure
     *
     * Implements fallback logging when audit processing fails
     *
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(\Exception $exception): void
    {
        error_log("Failed to process audit log: " . $exception->getMessage());

        // Implement fallback logging mechanism
        $this->logToFallbackFile();
    }

    /**
     * Get maximum retry attempts for audit logs
     *
     * @return int Max attempts
     */
    public function getMaxAttempts(): int
    {
        return 3; // Standard retry for audit logs
    }

    /**
     * Get timeout for audit log processing
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        return 30; // Audit logs should be quick
    }

    /**
     * Log to fallback file when database logging fails
     *
     * @return void
     */
    private function logToFallbackFile(): void
    {
        try {
            $data = $this->getData();

            // Create fallback log entry
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'uuid' => $this->getUuid(),
                'category' => $data['category'] ?? 'unknown',
                'action' => $data['action'] ?? 'unknown',
                'severity' => $data['severity'] ?? 'unknown',
                'context' => $data['context'] ?? [],
                'failed_at' => time(),
                'attempts' => $this->getAttempts()
            ];

            // Determine log file path
            $logPath = $this->getFallbackLogPath();

            // Ensure directory exists
            $logDir = dirname($logPath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            // Write to fallback file
            $logLine = json_encode($logEntry) . "\n";
            file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // Last resort: system error log
            error_log("Failed to write to audit fallback file: " . $e->getMessage());
            error_log("Original audit data: " . json_encode($this->getData()));
        }
    }

    /**
     * Get fallback log file path
     *
     * @return string Log file path
     */
    private function getFallbackLogPath(): string
    {
        return config('app.paths.logs') . 'jobs/failed_audit_logs.log';
    }

    /**
     * Create audit log job with validation
     *
     * @param string $category Audit category
     * @param string $action Action performed
     * @param string $severity Log severity
     * @param array $context Additional context
     * @return self ProcessAuditLog job
     * @throws \InvalidArgumentException If invalid parameters
     */
    public static function create(
        string $category,
        string $action,
        string $severity,
        array $context = []
    ): self {
        // Validate severity
        $validSeverities = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        if (!in_array($severity, $validSeverities)) {
            throw new \InvalidArgumentException(
                "Invalid severity '{$severity}'. Must be one of: " . implode(', ', $validSeverities)
            );
        }

        // Validate category and action
        if (empty(trim($category))) {
            throw new \InvalidArgumentException('Category cannot be empty');
        }

        if (empty(trim($action))) {
            throw new \InvalidArgumentException('Action cannot be empty');
        }

        return new self([
            'category' => $category,
            'action' => $action,
            'severity' => $severity,
            'context' => $context
        ]);
    }

    /**
     * Get job description for logging
     *
     * @return string Job description
     */
    public function getDescription(): string
    {
        $data = $this->getData();
        return sprintf(
            'ProcessAuditLog: %s.%s [%s] (UUID: %s)',
            $data['category'] ?? 'unknown',
            $data['action'] ?? 'unknown',
            $data['severity'] ?? 'unknown',
            $this->getUuid()
        );
    }

    /**
     * Check if audit data is valid
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        $data = $this->getData();

        return isset($data['category'], $data['action'], $data['severity']) &&
               !empty(trim($data['category'])) &&
               !empty(trim($data['action'])) &&
               !empty(trim($data['severity']));
    }

    /**
     * Get audit data summary for monitoring
     *
     * @return array Audit summary
     */
    public function getAuditSummary(): array
    {
        $data = $this->getData();

        return [
            'category' => $data['category'] ?? null,
            'action' => $data['action'] ?? null,
            'severity' => $data['severity'] ?? null,
            'has_context' => !empty($data['context']),
            'context_keys' => array_keys($data['context'] ?? [])
        ];
    }
}
