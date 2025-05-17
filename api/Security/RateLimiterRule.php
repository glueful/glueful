<?php

declare(strict_types=1);

namespace Glueful\Security;

use Glueful\Logging\AuditEvent;
use Glueful\Logging\AuditLogger;

/**
 * Rate Limiter Rule
 *
 * Defines dynamic rules for the adaptive rate limiter system.
 * These rules can be updated in real-time based on traffic patterns and detected threats.
 */
class RateLimiterRule
{
    /** @var string Unique identifier for this rule */
    private string $id;

    /** @var string Human-readable name for the rule */
    private string $name;

    /** @var string Rule description */
    private string $description;

    /** @var int Maximum attempts allowed within time window */
    private int $maxAttempts;

    /** @var int Time window in seconds */
    private int $windowSeconds;

    /** @var float Threshold for triggering rule based on behavior score (0.0-1.0) */
    private float $threshold;

    /** @var array Additional rule conditions */
    private array $conditions;

    /** @var bool Whether rule is currently active */
    private bool $active;

    /** @var int Priority of this rule (higher is more important) */
    private int $priority;

    /** @var \DateTime|null When the rule was last modified */
    private ?\DateTime $lastModified;

    /**
     * Constructor
     *
     * @param string $id Unique identifier
     * @param string $name Human-readable name
     * @param string $description Rule description
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param float $threshold Activation threshold (0.0-1.0)
     * @param array $conditions Additional rule conditions
     * @param bool $active Whether rule is active
     * @param int $priority Rule priority (higher is more important)
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
        int $priority = 10
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->threshold = max(0.0, min(1.0, $threshold)); // Ensure 0.0 - 1.0 range
        $this->conditions = $conditions;
        $this->active = $active;
        $this->priority = $priority;
        $this->lastModified = new \DateTime();

        // Audit log rule creation
        $this->auditRuleChange('rule_created');
    }

    /**
     * Get rule ID
     *
     * @return string Rule ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get rule name
     *
     * @return string Rule name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get rule description
     *
     * @return string Rule description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get maximum attempts allowed
     *
     * @return int Maximum attempts
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Set maximum attempts allowed
     *
     * @param int $maxAttempts New maximum attempts value
     * @return self Fluent interface
     */
    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->lastModified = new \DateTime();
        $this->auditRuleChange('rule_modified', ['property' => 'maxAttempts']);
        return $this;
    }

    /**
     * Get time window in seconds
     *
     * @return int Time window
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Set time window in seconds
     *
     * @param int $windowSeconds New time window value
     * @return self Fluent interface
     */
    public function setWindowSeconds(int $windowSeconds): self
    {
        $this->windowSeconds = max(1, $windowSeconds);
        $this->lastModified = new \DateTime();
        $this->auditRuleChange('rule_modified', ['property' => 'windowSeconds']);
        return $this;
    }

    /**
     * Get activation threshold
     *
     * @return float Threshold value
     */
    public function getThreshold(): float
    {
        return $this->threshold;
    }

    /**
     * Set activation threshold
     *
     * @param float $threshold New threshold value (0.0-1.0)
     * @return self Fluent interface
     */
    public function setThreshold(float $threshold): self
    {
        $this->threshold = max(0.0, min(1.0, $threshold));
        $this->lastModified = new \DateTime();
        $this->auditRuleChange('rule_modified', ['property' => 'threshold']);
        return $this;
    }

    /**
     * Get rule conditions
     *
     * @return array Rule conditions
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Set rule conditions
     *
     * @param array $conditions New rule conditions
     * @return self Fluent interface
     */
    public function setConditions(array $conditions): self
    {
        $this->conditions = $conditions;
        $this->lastModified = new \DateTime();
        $this->auditRuleChange('rule_modified', ['property' => 'conditions']);
        return $this;
    }

    /**
     * Add a condition to the rule
     *
     * @param string $key Condition key
     * @param mixed $value Condition value
     * @return self Fluent interface
     */
    public function addCondition(string $key, $value): self
    {
        $this->conditions[$key] = $value;
        $this->lastModified = new \DateTime();
        $this->auditRuleChange('rule_condition_added', ['condition' => $key]);
        return $this;
    }

    /**
     * Check if rule is active
     *
     * @return bool True if active
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Activate rule
     *
     * @return self Fluent interface
     */
    public function activate(): self
    {
        if (!$this->active) {
            $this->active = true;
            $this->lastModified = new \DateTime();
            $this->auditRuleChange('rule_activated');
        }
        return $this;
    }

    /**
     * Deactivate rule
     *
     * @return self Fluent interface
     */
    public function deactivate(): self
    {
        if ($this->active) {
            $this->active = false;
            $this->lastModified = new \DateTime();
            $this->auditRuleChange('rule_deactivated');
        }
        return $this;
    }

    /**
     * Get rule priority
     *
     * @return int Priority value
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Set rule priority
     *
     * @param int $priority New priority value
     * @return self Fluent interface
     */
    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        $this->lastModified = new \DateTime();
        $this->auditRuleChange('rule_modified', ['property' => 'priority']);
        return $this;
    }

    /**
     * Get last modified timestamp
     *
     * @return \DateTime Last modified time
     */
    public function getLastModified(): \DateTime
    {
        return $this->lastModified;
    }

    /**
     * Convert rule to array
     *
     * @return array Rule as associative array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'maxAttempts' => $this->maxAttempts,
            'windowSeconds' => $this->windowSeconds,
            'threshold' => $this->threshold,
            'conditions' => $this->conditions,
            'active' => $this->active,
            'priority' => $this->priority,
            'lastModified' => $this->lastModified->format('c'),
        ];
    }

    /**
     * Create rule from array
     *
     * @param array $data Rule data
     * @return self New rule instance
     */
    public static function fromArray(array $data): self
    {
        $rule = new self(
            $data['id'],
            $data['name'],
            $data['description'],
            $data['maxAttempts'],
            $data['windowSeconds'],
            $data['threshold'] ?? 0.5,
            $data['conditions'] ?? [],
            $data['active'] ?? true,
            $data['priority'] ?? 10
        );

        if (isset($data['lastModified'])) {
            $rule->lastModified = new \DateTime($data['lastModified']);
        }

        return $rule;
    }

    /**
     * Log rule changes to audit logger
     *
     * @param string $action Rule action
     * @param array $context Additional context
     */
    private function auditRuleChange(string $action, array $context = []): void
    {
        $auditLogger = new AuditLogger();
        $auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'rate_limit_rule_' . $action,
            AuditEvent::SEVERITY_INFO,
            array_merge([
                'rule_id' => $this->id,
                'rule_name' => $this->name,
                'max_attempts' => $this->maxAttempts,
                'window_seconds' => $this->windowSeconds,
                'active' => $this->active,
            ], $context)
        );
    }
}
