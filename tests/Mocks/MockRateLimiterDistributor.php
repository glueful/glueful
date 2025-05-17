<?php

namespace Tests\Mocks;

/**
 * Mock RateLimiterDistributor class for testing
 *
 * This class mocks the RateLimiterDistributor behavior for testing purposes
 * without requiring an actual distributor or modifying the original class.
 */
class MockRateLimiterDistributor
{
    /** @var array Global limits storage */
    private array $globalLimits = [];

    /** @var bool Whether this node is the primary coordinator */
    private bool $isPrimaryCoordinator = true;

    /**
     * Update global rate limit data
     *
     * @param string $key Rate limit key
     * @param int $currentCount Current count of attempts
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     * @return bool Success status
     */
    public function updateGlobalLimit(string $key, int $currentCount, int $maxAttempts, int $windowSeconds): bool
    {
        $this->globalLimits[$key] = [
            'key' => $key,
            'count' => $currentCount,
            'max' => $maxAttempts,
            'window' => $windowSeconds,
            'updated_at' => time(),
        ];

        return true;
    }

    /**
     * Get global rate limit data
     *
     * @param string $key Rate limit key
     * @return array|null Rate limit data or null if not found
     */
    public function getGlobalLimit(string $key): ?array
    {
        return $this->globalLimits[$key] ?? null;
    }

    /**
     * Check if this node is the primary coordinator
     *
     * @return bool True if this node is the primary coordinator
     */
    public function isPrimaryCoordinator(): bool
    {
        return $this->isPrimaryCoordinator;
    }

    /**
     * Set primary coordinator status (for testing)
     *
     * @param bool $isPrimary Whether this node is primary
     */
    public function setPrimaryCoordinator(bool $isPrimary): void
    {
        $this->isPrimaryCoordinator = $isPrimary;
    }
}
