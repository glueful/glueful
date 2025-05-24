<?php

namespace Glueful\Security;

use Glueful\Helpers\ConfigManager;
use Glueful\Exceptions\RateLimitExceededException;
use Glueful\Exceptions\SecurityException;

class SecurityManager
{
    private array $config;

    public function __construct()
    {
        $this->config = ConfigManager::get('security', []);
    }

    public function enforceRateLimit(string $ip): void
    {
        if (!($this->config['rate_limit']['enabled'] ?? true)) {
            return;
        }

        // Skip if IP is whitelisted
        $whitelist = $this->config['rate_limit']['whitelist_ips'] ?? [];
        if (in_array($ip, $whitelist)) {
            return;
        }

        // Implement rate limiting logic
        $limit = $this->config['rate_limit']['default_limit'] ?? 1000;
        $window = $this->config['rate_limit']['window_seconds'] ?? 3600;

        // This would integrate with your cache system
        $key = "rate_limit:$ip";
        $current = $this->getCacheValue($key, 0);

        if ($current >= $limit) {
            throw new RateLimitExceededException("Rate limit exceeded for IP: $ip", $window);
        }

        $this->incrementCacheValue($key, $window);
    }

    public function validateRequest($request): void
    {
        // Validate content type
        $contentType = $request->getHeaderLine('Content-Type');
        $allowedTypes = $this->config['request_validation']['allowed_content_types'] ?? [];

        if ($contentType && !empty($allowedTypes)) {
            $isAllowed = false;
            foreach ($allowedTypes as $type) {
                if (strpos($contentType, $type) === 0) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                throw new SecurityException("Unsupported content type: $contentType", 415);
            }
        }

        // Validate User-Agent if required
        if ($this->config['request_validation']['require_user_agent'] ?? false) {
            $userAgent = $request->getHeaderLine('User-Agent');
            if (empty($userAgent)) {
                throw new SecurityException("User-Agent header required");
            }
        }
    }

    private function getCacheValue(string $key, $default = null)
    {
        // Integrate with your cache system
        return $default;
    }

    private function incrementCacheValue(string $key, int $ttl): void
    {
        // Integrate with your cache system
    }
}
