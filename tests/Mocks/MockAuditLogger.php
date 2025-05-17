<?php

namespace Tests\Mocks;

use Glueful\Logging\AuditEvent;

/**
 * Mock AuditLogger class for testing
 *
 * This class mocks the AuditLogger behavior for testing purposes
 * without requiring an actual logger or modifying the original class.
 */
class MockAuditLogger
{
    /** @var array Audit logs storage */
    private static array $logs = [];

    /**
     * Reset all mock data
     */
    public static function reset(): void
    {
        self::$logs = [];
    }

    /**
     * Constructor
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        // No-op constructor to avoid database initialization
    }

    /**
     * Log an audit event
     *
     * @param string $category Event category
     * @param string $action Event action
     * @param string $severity Event severity
     * @param array $context Additional context
     * @return bool True if logged successfully
     */
    public function audit(string $category, string $action, string $severity, array $context = []): bool
    {
        self::$logs[] = [
            'category' => $category,
            'action' => $action,
            'severity' => $severity,
            'context' => $context,
            'timestamp' => time(),
        ];

        return true;
    }

    /**
     * Get all logged events
     *
     * @return array All logged events
     */
    public static function getLogs(): array
    {
        return self::$logs;
    }

    /**
     * Get logs by action
     *
     * @param string $action Action to filter by
     * @return array Filtered logs
     */
    public static function getLogsByAction(string $action): array
    {
        return array_filter(self::$logs, function ($log) use ($action) {
            return $log['action'] === $action;
        });
    }
}
