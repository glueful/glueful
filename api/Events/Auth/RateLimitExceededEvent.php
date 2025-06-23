<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Rate Limit Exceeded Event
 *
 * Dispatched when rate limits are exceeded.
 * Used for security monitoring, adaptive rate limiting, and blocking.
 *
 * @package Glueful\Events\Auth
 */
class RateLimitExceededEvent extends Event
{
    /**
     * @param string $clientIp Client IP address
     * @param string $rule Rate limit rule that was exceeded
     * @param int $currentCount Current request count
     * @param int $limit Rate limit threshold
     * @param int $windowSeconds Time window in seconds
     * @param array $metadata Additional metadata
     */
    public function __construct(
        private readonly string $clientIp,
        private readonly string $rule,
        private readonly int $currentCount,
        private readonly int $limit,
        private readonly int $windowSeconds,
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get client IP address
     *
     * @return string Client IP
     */
    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    /**
     * Get rate limit rule name
     *
     * @return string Rule name
     */
    public function getRule(): string
    {
        return $this->rule;
    }

    /**
     * Get current request count
     *
     * @return int Current count
     */
    public function getCurrentCount(): int
    {
        return $this->currentCount;
    }

    /**
     * Get rate limit threshold
     *
     * @return int Limit threshold
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get time window in seconds
     *
     * @return int Window seconds
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Get metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get how much the limit was exceeded by
     *
     * @return int Excess count
     */
    public function getExcessCount(): int
    {
        return max(0, $this->currentCount - $this->limit);
    }

    /**
     * Get excess percentage
     *
     * @return float Percentage over limit
     */
    public function getExcessPercentage(): float
    {
        if ($this->limit === 0) {
            return 0.0;
        }

        return ($this->getExcessCount() / $this->limit) * 100;
    }

    /**
     * Check if this is a severe violation
     *
     * @return bool True if severely over limit
     */
    public function isSevereViolation(): bool
    {
        return $this->getExcessPercentage() > 200; // More than 200% over limit
    }
}
