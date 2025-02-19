<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Security;

use Glueful\Api\Library\CacheEngine;

class RateLimiter
{
    private const PREFIX = 'rate_limit:';

    public function __construct(
        private readonly string $key,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds
    ) {
        CacheEngine::initialize('Glueful:', config('cache.default'));
    }

    public function attempt(): bool
    {
        $key = $this->getCacheKey();
        $now = time();

        // Remove expired entries
        CacheEngine::zremrangebyscore($key, '-inf', (string) ($now - $this->windowSeconds));

        // Get current attempt count
        if (CacheEngine::zcard($key) >= $this->maxAttempts) {
            return false;
        }

        // Add new request timestamp
        CacheEngine::zadd($key, [$now => $now]);

        // Set expiration time
        CacheEngine::expire($key, $this->windowSeconds);

        return true;
    }

    public function remaining(): int
    {
        return max(0, $this->maxAttempts - CacheEngine::zcard($this->getCacheKey()));
    }

    public function getRetryAfter(): int
    {
        $timestamps = CacheEngine::zrange($this->getCacheKey(), 0, 0);
        return empty($timestamps) ? 0 : max(0, (int) $timestamps[0] + $this->windowSeconds - time());
    }

    public function reset(): void
    {
        CacheEngine::delete($this->getCacheKey());
    }

    public function isExceeded(): bool
    {
        return !$this->attempt();
    }

    private function getCacheKey(): string
    {
        return self::PREFIX . $this->key;
    }

    public static function perIp(string $ip, int $maxAttempts, int $windowSeconds): self
    {
        return new self("ip:$ip", $maxAttempts, $windowSeconds);
    }

    public static function perUser(string $userId, int $maxAttempts, int $windowSeconds): self
    {
        return new self("user:$userId", $maxAttempts, $windowSeconds);
    }

    public static function perEndpoint(string $endpoint, string $identifier, int $maxAttempts, int $windowSeconds): self
    {
        return new self("endpoint:$endpoint:$identifier", $maxAttempts, $windowSeconds);
    }
}