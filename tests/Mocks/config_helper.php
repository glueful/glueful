<?php

/**
 * Mock config helper function
 *
 * Provides configuration values for testing.
 *
 * @param string $key Configuration key
 * @param mixed $default Default value if key not found
 * @return mixed Configuration value or default
 */
function config(string $key, mixed $default = null): mixed
{
    static $config = [
        'security.rate_limiter.enable_ml' => false,
    ];

    return $config[$key] ?? $default;
}
