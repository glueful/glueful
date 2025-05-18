<?php

declare(strict_types=1);

namespace Glueful\Cache\Health;

/**
 * Circuit Breaker
 *
 * Implements the circuit breaker pattern for preventing repeated calls to failing components.
 * Transitions between closed (operational), open (failing), and half-open (testing) states.
 */
class CircuitBreaker
{
    /** @var string Circuit is closed - operations proceed normally */
    public const STATE_CLOSED = 'closed';

    /** @var string Circuit is open - operations fail fast without attempting */
    public const STATE_OPEN = 'open';

    /** @var string Circuit is half-open - testing if service has recovered */
    public const STATE_HALF_OPEN = 'half-open';

    /** @var string Current circuit state */
    private $state = self::STATE_CLOSED;

    /** @var int Timestamp when circuit was last opened */
    private $openedAt = 0;

    /** @var int Timestamp when circuit will next attempt reset */
    private $resetAt = 0;

    /** @var int Failure count since last reset */
    private $failureCount = 0;

    /** @var int Threshold for failures before opening circuit */
    private $failureThreshold;

    /** @var int Reset timeout in seconds */
    private $resetTimeout;

    /** @var array Configuration for the circuit */
    private $config;

    /**
     * Initialize circuit breaker
     *
     * @param int $failureThreshold Number of failures before opening circuit
     * @param int $resetTimeout Time in seconds before attempting reset
     * @param array $config Additional configuration
     */
    public function __construct(int $failureThreshold = 5, int $resetTimeout = 60, array $config = [])
    {
        $this->failureThreshold = $failureThreshold;
        $this->resetTimeout = $resetTimeout;
        $this->config = $config;
    }

    /**
     * Check if a service call is allowed
     *
     * @return bool True if call should proceed
     */
    public function isCallAllowed(): bool
    {
        $this->updateState();

        return match ($this->state) {
            self::STATE_CLOSED => true,
            self::STATE_HALF_OPEN => true,
            self::STATE_OPEN => false,
            default => false
        };
    }

    /**
     * Record a successful service call
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        $this->failureCount = 0;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->transitionToClosed();
        }
    }

    /**
     * Record a failed service call
     *
     * @return void
     */
    public function recordFailure(): void
    {
        $this->failureCount++;

        if (
            $this->state === self::STATE_HALF_OPEN ||
            ($this->state === self::STATE_CLOSED && $this->failureCount >= $this->failureThreshold)
        ) {
            $this->transitionToOpen();
        }
    }

    /**
     * Get current circuit state
     *
     * @return string Current state
     */
    public function getState(): string
    {
        $this->updateState();
        return $this->state;
    }

    /**
     * Update circuit state based on timing
     *
     * @return void
     */
    private function updateState(): void
    {
        $now = time();

        if ($this->state === self::STATE_OPEN && $now >= $this->resetAt) {
            $this->transitionToHalfOpen();
        }
    }

    /**
     * Transition circuit to open state
     *
     * @return void
     */
    private function transitionToOpen(): void
    {
        $now = time();
        $this->state = self::STATE_OPEN;
        $this->openedAt = $now;
        $this->resetAt = $now + $this->resetTimeout;
    }

    /**
     * Transition circuit to half-open state
     *
     * @return void
     */
    private function transitionToHalfOpen(): void
    {
        $this->state = self::STATE_HALF_OPEN;
        $this->failureCount = 0;
    }

    /**
     * Transition circuit to closed state
     *
     * @return void
     */
    private function transitionToClosed(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->openedAt = 0;
        $this->resetAt = 0;
    }

    /**
     * Force circuit to a specific state
     *
     * @param string $state Target state
     * @return void
     */
    public function forceState(string $state): void
    {
        switch ($state) {
            case self::STATE_CLOSED:
                $this->transitionToClosed();
                break;

            case self::STATE_OPEN:
                $this->transitionToOpen();
                break;

            case self::STATE_HALF_OPEN:
                $this->transitionToHalfOpen();
                break;
        }
    }

    /**
     * Get circuit statistics
     *
     * @return array Circuit statistics
     */
    public function getStats(): array
    {
        $this->updateState();

        return [
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'failure_threshold' => $this->failureThreshold,
            'opened_at' => $this->openedAt,
            'reset_at' => $this->resetAt,
            'reset_timeout' => $this->resetTimeout
        ];
    }

    /**
     * Set failure threshold
     *
     * @param int $threshold Failure threshold
     * @return self
     */
    public function setFailureThreshold(int $threshold): self
    {
        $this->failureThreshold = max(1, $threshold);
        return $this;
    }

    /**
     * Set reset timeout
     *
     * @param int $timeout Reset timeout in seconds
     * @return self
     */
    public function setResetTimeout(int $timeout): self
    {
        $this->resetTimeout = max(1, $timeout);
        return $this;
    }
}
