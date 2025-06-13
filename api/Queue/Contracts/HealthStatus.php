<?php

namespace Glueful\Queue\Contracts;

/**
 * Health Status Class
 *
 * Represents the health status of a queue driver connection.
 * Used for monitoring, diagnostics, and load balancing decisions.
 *
 * Features:
 * - Overall health status tracking
 * - Detailed metrics collection
 * - Error message reporting
 * - Response time measurement
 * - Resource usage monitoring
 *
 * @package Glueful\Queue\Contracts
 */
class HealthStatus
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNHEALTHY = 'unhealthy';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Create new health status instance
     *
     * @param string $status Overall health status
     * @param array $metrics Health metrics and measurements
     * @param string $message Optional status message
     * @param float|null $responseTime Response time in milliseconds
     * @param \DateTime|null $checkedAt When health check was performed
     */
    public function __construct(
        public readonly string $status,
        public readonly array $metrics = [],
        public readonly string $message = '',
        public readonly ?float $responseTime = null,
        public readonly ?\DateTime $checkedAt = null
    ) {}

    /**
     * Create healthy status instance
     *
     * @param array $metrics Optional health metrics
     * @param string $message Optional status message
     * @param float|null $responseTime Response time in milliseconds
     * @return self Healthy status instance
     */
    public static function healthy(
        array $metrics = [],
        string $message = 'Connection is healthy',
        ?float $responseTime = null
    ): self {
        return new self(
            self::STATUS_HEALTHY,
            $metrics,
            $message,
            $responseTime,
            new \DateTime()
        );
    }

    /**
     * Create degraded status instance
     *
     * @param string $message Status message explaining degradation
     * @param array $metrics Optional health metrics
     * @param float|null $responseTime Response time in milliseconds
     * @return self Degraded status instance
     */
    public static function degraded(
        string $message,
        array $metrics = [],
        ?float $responseTime = null
    ): self {
        return new self(
            self::STATUS_DEGRADED,
            $metrics,
            $message,
            $responseTime,
            new \DateTime()
        );
    }

    /**
     * Create unhealthy status instance
     *
     * @param string $message Status message explaining problem
     * @param array $metrics Optional health metrics
     * @param float|null $responseTime Response time in milliseconds
     * @return self Unhealthy status instance
     */
    public static function unhealthy(
        string $message,
        array $metrics = [],
        ?float $responseTime = null
    ): self {
        return new self(
            self::STATUS_UNHEALTHY,
            $metrics,
            $message,
            $responseTime,
            new \DateTime()
        );
    }

    /**
     * Create unknown status instance
     *
     * @param string $message Status message
     * @param array $metrics Optional health metrics
     * @return self Unknown status instance
     */
    public static function unknown(string $message = 'Health status unknown', array $metrics = []): self
    {
        return new self(
            self::STATUS_UNKNOWN,
            $metrics,
            $message,
            null,
            new \DateTime()
        );
    }

    /**
     * Check if status is healthy
     *
     * @return bool True if status is healthy
     */
    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    /**
     * Check if status is degraded
     *
     * @return bool True if status is degraded
     */
    public function isDegraded(): bool
    {
        return $this->status === self::STATUS_DEGRADED;
    }

    /**
     * Check if status is unhealthy
     *
     * @return bool True if status is unhealthy
     */
    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }

    /**
     * Check if driver is operational (healthy or degraded)
     *
     * @return bool True if driver can handle requests
     */
    public function isOperational(): bool
    {
        return in_array($this->status, [self::STATUS_HEALTHY, self::STATUS_DEGRADED], true);
    }

    /**
     * Get specific metric value
     *
     * @param string $key Metric key
     * @param mixed $default Default value if metric not found
     * @return mixed Metric value
     */
    public function getMetric(string $key, $default = null)
    {
        return $this->metrics[$key] ?? $default;
    }

    /**
     * Convert health status to array format
     *
     * @return array Health status as associative array
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'response_time' => $this->responseTime,
            'checked_at' => $this->checkedAt?->format('c'),
            'metrics' => $this->metrics
        ];
    }

    /**
     * Get JSON representation of health status
     *
     * @return string JSON encoded health status
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}