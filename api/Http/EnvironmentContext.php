<?php

declare(strict_types=1);

namespace Glueful\Http;

/**
 * Environment Context Service
 *
 * Provides abstracted access to environment variables, eliminating direct $_ENV usage.
 * All environment variable access should go through this service.
 *
 * @package Glueful\Http
 */
class EnvironmentContext
{
    private array $env;
    private array $cache = [];

    public function __construct()
    {
        // Copy environment variables to avoid direct access
        $this->env = array_merge($_ENV, getenv());
    }

    /**
     * Get environment variable
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        // Check cache first
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Check $_ENV first
        if (isset($this->env[$key])) {
            $value = $this->env[$key];
        } else {
            // Fall back to getenv()
            $value = getenv($key);
            if ($value === false) {
                return $default;
            }
        }

        // Cache the value
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Set environment variable
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    public function set(string $key, string $value): void
    {
        $this->env[$key] = $value;
        $this->cache[$key] = $value;
        putenv("$key=$value");
    }

    /**
     * Check if environment variable exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->env[$key]) || getenv($key) !== false;
    }

    /**
     * Get application environment
     *
     * @return string
     */
    public function getAppEnv(): string
    {
        return $this->get('APP_ENV', 'production');
    }

    /**
     * Check if in production
     *
     * @return bool
     */
    public function isProduction(): bool
    {
        return $this->getAppEnv() === 'production';
    }

    /**
     * Check if in development
     *
     * @return bool
     */
    public function isDevelopment(): bool
    {
        return $this->getAppEnv() === 'development';
    }

    /**
     * Check if in testing
     *
     * @return bool
     */
    public function isTesting(): bool
    {
        return $this->getAppEnv() === 'testing';
    }

    /**
     * Get database name
     *
     * @return string|null
     */
    public function getDatabaseName(): ?string
    {
        return $this->get('DB_NAME') ?? $this->get('DB_DATABASE');
    }

    /**
     * Get encryption key
     *
     * @param string $type
     * @return string|null
     */
    public function getEncryptionKey(string $type = 'default'): ?string
    {
        switch ($type) {
            case 'archive':
                return $this->get('ARCHIVE_ENCRYPTION_KEY');
            case 'app':
                return $this->get('APP_KEY');
            default:
                return $this->get('ENCRYPTION_KEY') ?? $this->get('APP_KEY');
        }
    }

    /**
     * Check if graceful degradation mode is enabled
     *
     * @return bool
     */
    public function isGracefulDegradationMode(): bool
    {
        return $this->get('GRACEFUL_DEGRADATION_MODE', 'false') === 'true';
    }

    /**
     * Get all environment variables (filtered for security)
     *
     * @param bool $includeSensitive
     * @return array
     */
    public function all(bool $includeSensitive = false): array
    {
        if ($includeSensitive) {
            return $this->env;
        }

        // Filter out sensitive keys
        $sensitive = [
            'DB_PASSWORD',
            'APP_KEY',
            'JWT_KEY',
            'ENCRYPTION_KEY',
            'SECRET',
            'PASSWORD',
            'TOKEN',
            'PRIVATE_KEY'
        ];

        $filtered = [];
        foreach ($this->env as $key => $value) {
            $isSensitive = false;
            foreach ($sensitive as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if (!$isSensitive) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
