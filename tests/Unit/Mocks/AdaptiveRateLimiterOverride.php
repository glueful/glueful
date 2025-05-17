<?php

/**
 * Override file for AdaptiveRateLimiter that adds testing hooks
 */

namespace Glueful\Security;

// Override AdaptiveRateLimiter for testing purposes
// This class intercepts constructor calls and other methods for unit testing

class AdaptiveRateLimiter extends RateLimiter
{
    /** @var string Rate limiter key */
    protected string $key;

    /** @var int Maximum attempts allowed */
    protected int $maxAttempts;

    /** @var int Time window in seconds */
    protected int $windowSeconds;

    /** @var array Request context information */
    private array $context = [];

    /** @var bool Whether distributed rate limiting is enabled */
    private bool $enableDistributed = false;

    /**
     * Constructor
     *
     * @param string $key Unique identifier for this rate limiter
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     * @param array $context Additional request context
     * @param bool $enableDistributed Whether to enable distributed rate limiting
     */
    public function __construct(
        string $key,
        int $maxAttempts,
        int $windowSeconds,
        array $context = [],
        bool $enableDistributed = false
    ) {
        // Don't call parent constructor as it would fail in tests
        // parent::__construct($key, $maxAttempts, $windowSeconds);

        // Store properties directly
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->context = $context;
        $this->enableDistributed = $enableDistributed;
    }

    /**
     * Get active applicable rules based on current behavior score
     *
     * @return array<string, mixed> Applicable rules
     */
    public function getActiveApplicableRules(): array
    {
        // Empty implementation for testing
        return [];
    }

    /**
     * Get current behavior score
     *
     * @return float Behavior score (0.0 = normal, 1.0 = highly suspicious)
     */
    public function getBehaviorScore(): float
    {
        // Default implementation for testing
        return 0.0;
    }

    /**
     * Record and validate attempt
     *
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool
    {
        // Default implementation - always allow in tests unless overridden
        return true;
    }

    /**
     * Get remaining attempts
     *
     * @return int Remaining attempts
     */
    public function remaining(): int
    {
        // Default implementation for testing
        return $this->maxAttempts - 1;
    }

    /**
     * Get retry delay
     *
     * @return int Seconds until next attempt allowed
     */
    public function getRetryAfter(): int
    {
        // Default implementation for testing
        return 30;
    }

    /**
     * Check if limit exceeded
     *
     * @return bool True if rate limit is exceeded
     */
    public function isExceeded(): bool
    {
        // Default implementation - never exceeded in tests unless overridden
        return false;
    }
}
