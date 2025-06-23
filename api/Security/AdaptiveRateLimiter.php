<?php

declare(strict_types=1);

namespace Glueful\Security;

use Glueful\Cache\CacheStore;
use Glueful\Http\RequestContext;
use Glueful\Logging\AuditEvent;
use Glueful\Logging\AuditLogger;
use Glueful\Helpers\Utils;
use Glueful\Helpers\CacheHelper;

/**
 * Adaptive Rate Limiter
 *
 * Extends the base rate limiter with adaptive capabilities:
 * - Behavior-based rate limiting using user profiling and anomaly detection
 * - Machine learning integration for threshold adjustment and attack detection
 * - Progressive rate limiting based on behavior patterns
 * - Distributed rate limiting via RateLimiterDistributor
 */
class AdaptiveRateLimiter extends RateLimiter
{
    /** @var string Cache key prefix for behavioral profiles */
    private const BEHAVIOR_PREFIX = 'behavior_profile:';

    /** @var string Cache key prefix for anomaly scores */
    private const ANOMALY_PREFIX = 'anomaly_score:';

    /** @var string Cache key prefix for rate limit entries */
    private const PREFIX = 'rate_limit:';

    /** @var RateLimiterDistributor|null Distributor for cluster coordination */
    private ?RateLimiterDistributor $distributor = null;

    /** @var array<string, RateLimiterRule> Active rules for this limiter */
    private array $rules = [];

    /** @var float Current behavior score (0.0 = normal, 1.0 = highly suspicious) */
    private float $behaviorScore = 0.0;

    /** @var array Request context information */
    private array $context = [];

    /** @var RequestContext Request context service */
    private RequestContext $requestContext;

    /** @var bool Whether machine learning features are enabled */
    private bool $mlEnabled = false;

    /** @var string Identifier for tracking entity (IP, user, etc.) */
    private string $trackingId = '';

    /** @var string Key for identifying this rate limiter */
    private string $limitKey;

    /** @var int Maximum number of attempts allowed (copy of parent private property) */
    private int $maxAttempts;

    /** @var int Time window in seconds (copy of parent private property) */
    private int $windowSeconds;

    /**
     * Constructor
     *
     * @param string $key Unique identifier for this rate limiter
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     * @param array $context Additional request context
     * @param bool $enableDistributed Whether to enable distributed rate limiting
     * @param CacheStore|null $cache Cache driver instance
     * @param RequestContext|null $requestContext Request context instance
     */
    public function __construct(
        string $key,
        int $maxAttempts,
        int $windowSeconds,
        array $context = [],
        bool $enableDistributed = false,
        ?CacheStore $cache = null,
        ?RequestContext $requestContext = null
    ) {
        // Pass cache to parent constructor
        $cache = $cache ?? CacheHelper::createCacheInstance();
        if ($cache === null) {
            throw new \RuntimeException(
                'Cache is required for AdaptiveRateLimiter. Please ensure cache is properly configured.'
            );
        }
        parent::__construct($key, $maxAttempts, $windowSeconds, $cache, $requestContext);

        // Store key and limits locally because parent properties are private
        $this->limitKey = $key;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;

        // Store request context
        $this->context = $context;
        $this->requestContext = $requestContext ?? RequestContext::fromGlobals();

        // Extract tracking ID from key
        $parts = explode(':', $key);
        if (count($parts) >= 2) {
            $this->trackingId = $parts[1];
        }

        // Initialize distributor if enabled
        if ($enableDistributed) {
            $this->distributor = new RateLimiterDistributor();
        }

        // Check if ML features are enabled in config
        $this->mlEnabled = (bool) config('security.rate_limiter.enable_ml', false);

        // Load any predefined rules
        $this->loadRules();

        // Pre-compute behavior score
        $this->computeBehaviorScore();
    }

    /**
     * Record and validate attempt with adaptive features
     *
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool
    {
        // Check if any active rule triggers a stricter limit
        $adjustedLimit = $this->getAdjustedLimitFromRules();
        if ($adjustedLimit < $this->maxAttempts) {
            // Create temporary rate limiter with stricter limits
            $tempLimiter = new RateLimiter($this->limitKey, $adjustedLimit, $this->windowSeconds);
            $allowed = $tempLimiter->attempt();

            if (!$allowed) {
                $this->logAdaptiveRateLimit('stricter_rule_applied', [
                    'normal_limit' => $this->maxAttempts,
                    'adjusted_limit' => $adjustedLimit,
                    'behavior_score' => $this->behaviorScore,
                    'rules_applied' => array_keys($this->getActiveApplicableRules()),
                ]);

                return false;
            }
        }

        // Apply progressive rate limiting based on behavior score
        if ($this->behaviorScore > 0.6) {
            // High suspicion score, apply stricter limits
            $progressiveLimit = (int) round($this->maxAttempts * (1 - $this->behaviorScore * 0.5));
            if ($progressiveLimit < $this->maxAttempts) {
                $tempLimiter = new RateLimiter($this->limitKey, $progressiveLimit, $this->windowSeconds);
                $allowed = $tempLimiter->attempt();

                if (!$allowed) {
                    $this->logAdaptiveRateLimit('progressive_limit_applied', [
                        'normal_limit' => $this->maxAttempts,
                        'progressive_limit' => $progressiveLimit,
                        'behavior_score' => $this->behaviorScore,
                    ]);

                    return false;
                }
            }
        }

        // Update distributed limits if enabled
        if ($this->distributor) {
            $key = $this->getCacheKey();
            $currentCount = $this->cache->zcard($key);
            $this->distributor->updateGlobalLimit(
                $this->limitKey,
                $currentCount,
                $this->maxAttempts,
                $this->windowSeconds
            );
        }

        // Call the parent implementation for normal rate limiting
        $allowed = parent::attempt();

        // Update behavior profile with this attempt
        if ($allowed) {
            $this->updateBehaviorProfile();
        } else {
            // Log when normal rate limit is exceeded
            $this->logAdaptiveRateLimit('normal_limit_exceeded', [
                'behavior_score' => $this->behaviorScore,
            ]);
        }

        return $allowed;
    }

    /**
     * Get active applicable rules based on current behavior score
     *
     * @return array<string, RateLimiterRule> Applicable rules
     */
    public function getActiveApplicableRules(): array
    {
        $applicableRules = [];

        foreach ($this->rules as $id => $rule) {
            if (!$rule->isActive()) {
                continue;
            }

            if ($this->behaviorScore >= $rule->getThreshold()) {
                $applicableRules[$id] = $rule;
            }
        }

        return $applicableRules;
    }

    /**
     * Get adjusted rate limit based on active rules
     *
     * @return int Adjusted max attempts limit
     */
    private function getAdjustedLimitFromRules(): int
    {
        $applicableRules = $this->getActiveApplicableRules();
        if (empty($applicableRules)) {
            return $this->maxAttempts;
        }

        // Sort rules by priority (highest first)
        uasort($applicableRules, function (RateLimiterRule $a, RateLimiterRule $b) {
            return $b->getPriority() <=> $a->getPriority();
        });

        // Use the strictest limit from applicable rules
        $strictestLimit = $this->maxAttempts;

        foreach ($applicableRules as $rule) {
            $ruleLimit = $rule->getMaxAttempts();
            if ($ruleLimit < $strictestLimit) {
                $strictestLimit = $ruleLimit;
            }
        }

        return $strictestLimit;
    }

    /**
     * Add a rule to this rate limiter
     *
     * @param RateLimiterRule $rule Rule to add
     * @return self Fluent interface
     */
    public function addRule(RateLimiterRule $rule): self
    {
        $this->rules[$rule->getId()] = $rule;
        return $this;
    }

    /**
     * Remove a rule from this rate limiter
     *
     * @param string $ruleId Rule ID to remove
     * @return self Fluent interface
     */
    public function removeRule(string $ruleId): self
    {
        if (isset($this->rules[$ruleId])) {
            unset($this->rules[$ruleId]);
        }
        return $this;
    }

    /**
     * Get all rules for this rate limiter
     *
     * @return array<string, RateLimiterRule> All rules
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get current behavior score
     *
     * @return float Behavior score (0.0 = normal, 1.0 = highly suspicious)
     */
    public function getBehaviorScore(): float
    {
        return $this->behaviorScore;
    }

    /**
     * Load predefined rules from configuration or storage
     */
    private function loadRules(): void
    {
        // Try to load rules from cache first
        $rulesKey = Utils::sanitizeCacheKey('rate_limiter_rules:' . $this->limitKey);
        $cachedRules = $this->cache->get($rulesKey);

        if ($cachedRules) {
            $ruleData = json_decode($cachedRules, true);
            if (is_array($ruleData)) {
                foreach ($ruleData as $ruleArray) {
                    $rule = RateLimiterRule::fromArray($ruleArray);
                    $this->rules[$rule->getId()] = $rule;
                }
                return;
            }
        }

        // Default rules if no cached rules found
        $defaultRules = $this->getDefaultRules();
        foreach ($defaultRules as $rule) {
            $this->rules[$rule->getId()] = $rule;
        }

        // Cache the rules
        $this->saveRulesToCache();
    }

    /**
     * Get default set of rules
     *
     * @return array Default rules
     */
    private function getDefaultRules(): array
    {
        $keyType = explode(':', $this->limitKey)[0] ?? 'generic';

        $rules = [];

        // Suspicious activity rule
        $rules[] = new RateLimiterRule(
            'suspicious_activity',
            'Suspicious Activity',
            'Applies stricter limits when suspicious behavior is detected',
            (int) round($this->maxAttempts * 0.5),
            $this->windowSeconds,
            0.75
        );

        // Burst traffic rule
        $rules[] = new RateLimiterRule(
            'burst_traffic',
            'Burst Traffic',
            'Limits unusually rapid request bursts',
            (int) round($this->maxAttempts * 0.7),
            $this->windowSeconds,
            0.6
        );

        if ($keyType === 'ip') {
            // IP-specific rules
            $rules[] = new RateLimiterRule(
                'multiple_accounts',
                'Multiple Account Creation',
                'Detects attempts to create multiple accounts from same IP',
                (int) round($this->maxAttempts * 0.3),
                $this->windowSeconds,
                0.7
            );
        } elseif ($keyType === 'user') {
            // User-specific rules
            $rules[] = new RateLimiterRule(
                'account_testing',
                'Account Testing',
                'Detects attempts to test account capabilities or limits',
                (int) round($this->maxAttempts * 0.5),
                $this->windowSeconds,
                0.65
            );
        } elseif ($keyType === 'endpoint') {
            // Endpoint-specific rules
            $rules[] = new RateLimiterRule(
                'endpoint_abuse',
                'Endpoint Abuse',
                'Detects attempts to abuse specific API endpoints',
                (int) round($this->maxAttempts * 0.6),
                $this->windowSeconds,
                0.5
            );
        }

        return $rules;
    }

    /**
     * Save rules to cache
     */
    private function saveRulesToCache(): void
    {
        $rulesKey = Utils::sanitizeCacheKey('rate_limiter_rules:' . $this->limitKey);
        $ruleData = [];

        foreach ($this->rules as $rule) {
            $ruleData[] = $rule->toArray();
        }

        $this->cache->set($rulesKey, json_encode($ruleData), 3600);
    }


    /**
     * Compute behavior score based on historical patterns
     */
    private function computeBehaviorScore(): void
    {
        if (empty($this->trackingId)) {
            return;
        }

        $behaviorKey = self::BEHAVIOR_PREFIX . $this->trackingId;
        $behaviorData = $this->cache->get($behaviorKey);

        if (!$behaviorData) {
            // No existing behavior profile, start with neutral score
            $this->behaviorScore = 0.25;
            return;
        }

        $profile = json_decode($behaviorData, true);
        if (!is_array($profile) || !isset($profile['anomaly_score'])) {
            $this->behaviorScore = 0.25;
            return;
        }

        $this->behaviorScore = (float) $profile['anomaly_score'];

        // Apply behavior-based adjustments instead of ML
        // This section uses statistical analysis of the behavior profile
        if ($this->mlEnabled) {
            // ML is enabled in config but not available - use advanced statistical approach
            $additionalScore = 0.0;

            // Check for rapid succession patterns
            if (isset($profile['rapid_request_ratio']) && $profile['rapid_request_ratio'] > 0.6) {
                $additionalScore += 0.15;
            }

            // Check for unusual request patterns
            if (isset($profile['interval_variance']) && $profile['interval_variance'] < 0.05) {
                // Very low variance suggests automation
                $additionalScore += 0.2;
            }

            // Check request volume
            if (isset($profile['request_count']) && isset($profile['first_seen'])) {
                $timePeriod = max(1, time() - $profile['first_seen']);
                $requestRate = $profile['request_count'] / $timePeriod;

                if ($requestRate > 0.5) { // More than 1 request per 2 seconds on average
                    $additionalScore += min(0.25, $requestRate * 0.2);
                }
            }

            // Apply a weighted adjustment to the behavior score
            $this->behaviorScore = $this->behaviorScore * 0.7 + $additionalScore * 0.3;

            // Log the statistical adjustment
            $auditLogger = AuditLogger::getInstance();
            $auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'statistical_adjustment_applied',
                AuditEvent::SEVERITY_INFO,
                [
                    'original_score' => $profile['anomaly_score'],
                    'adjusted_score' => $this->behaviorScore,
                    'tracking_id' => $this->trackingId
                ]
            );
        }

        // Ensure score is within valid range
        $this->behaviorScore = max(0.0, min(1.0, $this->behaviorScore));
    }

    /**
     * Update behavior profile with current request data
     */
    private function updateBehaviorProfile(): void
    {
        if (empty($this->trackingId)) {
            return;
        }

        $behaviorKey = self::BEHAVIOR_PREFIX . $this->trackingId;
        $existingData = $this->cache->get($behaviorKey);

        $profile = [];
        if ($existingData) {
            $profile = json_decode($existingData, true) ?: [];
        }

        // Update basic metrics
        $profile['request_count'] = ($profile['request_count'] ?? 0) + 1;
        $profile['last_seen'] = time();
        $profile['user_agent'] = $this->requestContext->getUserAgent();

        // Calculate time since last request
        $lastRequestTime = $profile['last_request_time'] ?? 0;
        $now = microtime(true);
        $timeDiff = $lastRequestTime > 0 ? $now - $lastRequestTime : 0;
        $profile['last_request_time'] = $now;

        // Track request intervals
        $profile['intervals'] = $profile['intervals'] ?? [];
        if ($timeDiff > 0) {
            $profile['intervals'][] = $timeDiff;
            // Keep only the last 20 intervals
            if (count($profile['intervals']) > 20) {
                $profile['intervals'] = array_slice($profile['intervals'], -20);
            }
        }

        // Calculate interval statistics
        if (count($profile['intervals']) >= 5) {
            $profile['avg_interval'] = array_sum($profile['intervals']) / count($profile['intervals']);

            // Detect rapid succession requests
            $rapidRequests = 0;
            foreach ($profile['intervals'] as $interval) {
                if ($interval < 1.0) { // Less than 1 second
                    $rapidRequests++;
                }
            }
            $profile['rapid_request_ratio'] = $rapidRequests / count($profile['intervals']);

            // Update anomaly score based on patterns
            $anomalyScore = 0.0;

            // Factor 1: Rapid requests ratio
            $anomalyScore += $profile['rapid_request_ratio'] * 0.4;

            // Factor 2: Variance in request intervals
            $avgInterval = $profile['avg_interval'];
            $varSum = 0;
            foreach ($profile['intervals'] as $interval) {
                $varSum += pow($interval - $avgInterval, 2);
            }
            $variance = $varSum / count($profile['intervals']);
            $profile['interval_variance'] = $variance;

            // Low variance can indicate automation
            if ($variance < 0.1 && $avgInterval < 2.0) {
                $anomalyScore += 0.3;
            }

            // Factor 3: Request volume
            $requestRate = $profile['request_count'] / (time() - ($profile['first_seen'] ?? time()));
            $profile['request_rate'] = $requestRate;
            if ($requestRate > 0.2) { // More than 1 request per 5 seconds on average
                $anomalyScore += min(0.3, $requestRate * 0.5);
            }

            // Ensure score is in range and apply smoothing
            $anomalyScore = max(0.0, min(1.0, $anomalyScore));
            $profile['anomaly_score'] = isset($profile['anomaly_score'])
                ? $profile['anomaly_score'] * 0.7 + $anomalyScore * 0.3
                : $anomalyScore;

            // Update behavior score property
            $this->behaviorScore = $profile['anomaly_score'];
        }

        // Store first seen time if not set
        if (!isset($profile['first_seen'])) {
            $profile['first_seen'] = time();
        }

        // Save updated profile
        $this->cache->set($behaviorKey, json_encode($profile), 86400); // 24 hours

        // Save anomaly score separately with longer TTL for historical analysis
        if (isset($profile['anomaly_score'])) {
            $anomalyKey = self::ANOMALY_PREFIX . $this->trackingId;
            $this->cache->set($anomalyKey, (string) $profile['anomaly_score'], 604800); // 7 days
        }
    }

    /**
     * Log adaptive rate limit events to audit logger
     *
     * @param string $action Event action
     * @param array $context Additional context
     */
    private function logAdaptiveRateLimit(string $action, array $context = []): void
    {
        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'adaptive_rate_limit_' . $action,
            AuditEvent::SEVERITY_INFO,
            array_merge([
                'key' => $this->limitKey,
                'max_attempts' => $this->maxAttempts,
                'window_seconds' => $this->windowSeconds,
                'ip_address' => $this->requestContext->getClientIp(),
            ], $context)
        );
    }

    /**
     * Get cache key
     *
     * @return string Prefixed cache key
     */
    protected function getCacheKey(): string
    {
        return self::PREFIX . $this->limitKey;
    }

    /**
     * Create an adaptive IP-based rate limiter
     *
     * @param string $ip IP address to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param bool $distributed Enable distributed coordination
     * @return self Adaptive rate limiter instance
     */
    public static function perIp(
        string $ip,
        int $maxAttempts,
        int $windowSeconds,
        bool $distributed = false
    ): self {
        $requestContext = RequestContext::fromGlobals();
        $context = [
            'ip' => $ip,
            'user_agent' => $requestContext->getUserAgent(),
            'request_uri' => $requestContext->getRequestUri(),
        ];

        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'adaptive_rate_limit_ip_created',
            AuditEvent::SEVERITY_INFO,
            [
                'ip' => $ip,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'distributed' => $distributed,
            ]
        );

        return new self(
            "ip:$ip",
            $maxAttempts,
            $windowSeconds,
            $context,
            $distributed,
            CacheHelper::createCacheInstance(),
            $requestContext
        );
    }

    /**
     * Create an adaptive user-based rate limiter
     *
     * @param string $userId User identifier to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param bool $distributed Enable distributed coordination
     * @return self Adaptive rate limiter instance
     */
    public static function perUser(
        string $userId,
        int $maxAttempts,
        int $windowSeconds,
        bool $distributed = false
    ): self {
        $requestContext = RequestContext::fromGlobals();
        $context = [
            'ip' => $requestContext->getClientIp(),
            'user_agent' => $requestContext->getUserAgent(),
            'request_uri' => $requestContext->getRequestUri(),
        ];

        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'adaptive_rate_limit_user_created',
            AuditEvent::SEVERITY_INFO,
            [
                'user_id' => $userId,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'distributed' => $distributed,
            ]
        );

        return new self(
            "user:$userId",
            $maxAttempts,
            $windowSeconds,
            $context,
            $distributed,
            CacheHelper::createCacheInstance(),
            $requestContext
        );
    }

    /**
     * Create an adaptive endpoint-specific rate limiter
     *
     * @param string $endpoint API endpoint to track
     * @param string $identifier Unique request identifier
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param bool $distributed Enable distributed coordination
     * @return self Adaptive rate limiter instance
     */
    public static function perEndpoint(
        string $endpoint,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds,
        bool $distributed = false
    ): self {
        $requestContext = RequestContext::fromGlobals();
        $context = [
            'ip' => $requestContext->getClientIp(),
            'user_agent' => $requestContext->getUserAgent(),
            'endpoint' => $endpoint,
        ];

        $auditLogger = AuditLogger::getInstance();
        $auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'adaptive_rate_limit_endpoint_created',
            AuditEvent::SEVERITY_INFO,
            [
                'endpoint' => $endpoint,
                'identifier' => $identifier,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds,
                'distributed' => $distributed,
            ]
        );

        return new self(
            "endpoint:$endpoint:$identifier",
            $maxAttempts,
            $windowSeconds,
            $context,
            $distributed,
            CacheHelper::createCacheInstance(),
            $requestContext
        );
    }
}
