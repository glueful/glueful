<?php

namespace Tests\Mocks;

/**
 * Mock RateLimiterRule class for testing
 */
class MockRateLimiterRule
{
    /** @var string Rule ID */
    private string $id;

    /** @var string Rule name */
    private string $name;

    /** @var string Rule description */
    private string $description;

    /** @var int Maximum attempts */
    private int $maxAttempts;

    /** @var int Window seconds */
    private int $windowSeconds;

    /** @var float Behavior threshold */
    private float $threshold;

    /** @var int Rule priority */
    private int $priority;

    /** @var bool Whether rule is active */
    private bool $active;

    /** @var array Rule conditions */
    private array $conditions;

    /**
     * Constructor
     *
     * @param string $id Rule ID
     * @param string $name Rule name
     * @param string $description Rule description
     * @param int $maxAttempts Maximum attempts
     * @param int $windowSeconds Window seconds
     * @param float $threshold Behavior threshold
     * @param int $priority Rule priority
     * @param bool $active Whether rule is active
     */
    public function __construct(
        string $id,
        string $name,
        string $description,
        int $maxAttempts,
        int $windowSeconds,
        float $threshold = 0.5,
        array $conditions = [],
        bool $active = true,
        int $priority = 0
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->threshold = $threshold;
        $this->conditions = $conditions;
        $this->active = $active;
        $this->priority = $priority;
    }

    /**
     * Override to disable audit logging in tests
     *
     * @param string $action Rule action
     * @param array $context Additional context
     */
    protected function auditRuleChange(string $action, array $context = []): void
    {
        // Do nothing in tests to avoid external dependencies
    }

    /**
     * Create rule from array for testing
     *
     * @param array $data Rule data
     * @return self Rule instance
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'] ?? '',
            $data['description'] ?? '',
            $data['max_attempts'] ?? 10,
            $data['window_seconds'] ?? 60,
            $data['threshold'] ?? 0.5,
            $data['conditions'] ?? [],
            $data['active'] ?? true,
            $data['priority'] ?? 0
        );
    }
}
