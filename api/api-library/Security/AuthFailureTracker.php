<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

use Glueful\Api\Library\CacheEngine;

class AuthFailureTracker
{
    private const PREFIX = 'auth_fail:';
    
    public function __construct(
        private readonly string $key, // Either user ID or IP
        private readonly int $maxAttempts = 5,
        private readonly int $decaySeconds = 900 // 15 minutes
    ) {
        CacheEngine::initialize('Glueful:', config('cache.default'));
    }

    public function recordFailure(): void
    {
        $key = $this->getCacheKey();
        $attempts = (int)CacheEngine::get($key) ?? 0;

        if ($attempts === 0) {
            CacheEngine::set($key, 1, $this->decaySeconds);
        } else {
            CacheEngine::increment($key);
        }
    }

    public function getFailures(): int
    {
        return (int)CacheEngine::get($this->getCacheKey()) ?? 0;
    }

    public function resetFailures(): void
    {
        CacheEngine::delete($this->getCacheKey());
    }

    public function isBlocked(): bool
    {
        return $this->getFailures() >= $this->maxAttempts;
    }

    public function getRetryAfter(): int
    {
        return CacheEngine::ttl($this->getCacheKey());
    }

    private function getCacheKey(): string
    {
        return self::PREFIX . $this->key;
    }

    /**
     * Track failures per user ID
     */
    public static function forUser(string $userId, int $maxAttempts = 5, int $decaySeconds = 900): self
    {
        return new self("user:$userId", $maxAttempts, $decaySeconds);
    }

    /**
     * Track failures per IP address
     */
    public static function forIp(string $ip, int $maxAttempts = 5, int $decaySeconds = 900): self
    {
        return new self("ip:$ip", $maxAttempts, $decaySeconds);
    }
}