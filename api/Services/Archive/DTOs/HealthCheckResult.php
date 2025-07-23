<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Health Check Result
 *
 * Contains the results of archive system health checks including
 * status, issues, warnings, and detailed metrics.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class HealthCheckResult
{
    public function __construct(
        public readonly bool $healthy,
        public readonly array $issues = [],
        public readonly array $warnings = [],
        public readonly array $metrics = []
    ) {
    }

    /**
     * Check if there are any critical issues
     */
    public function hasCriticalIssues(): bool
    {
        return !$this->healthy || !empty($this->issues);
    }

    /**
     * Check if there are any warnings
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Get all messages (issues and warnings)
     */
    public function getAllMessages(): array
    {
        return array_merge(
            array_map(fn($issue) => ['type' => 'error', 'message' => $issue], $this->issues),
            array_map(fn($warning) => ['type' => 'warning', 'message' => $warning], $this->warnings)
        );
    }

    /**
     * Get a summary status
     */
    public function getStatus(): string
    {
        if (!$this->healthy) {
            return 'critical';
        }

        if ($this->hasWarnings()) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'status' => $this->getStatus(),
            'healthy' => $this->healthy,
            'issues' => $this->issues,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
