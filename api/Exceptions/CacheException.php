<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * Cache Exception
 *
 * Exception thrown by cache operations for invalid arguments
 * Implements PSR-16 InvalidArgumentException
 */
class CacheException extends \Exception implements InvalidArgumentException
{
    /**
     * Create cache exception for invalid key
     *
     * @param string $key Invalid cache key
     * @return self
     */
    public static function invalidKey(string $key): self
    {
        return new self("Invalid cache key: '{$key}'");
    }

    /**
     * Create cache exception for empty key
     *
     * @return self
     */
    public static function emptyKey(): self
    {
        return new self('Cache key cannot be empty');
    }

    /**
     * Create cache exception for invalid characters
     *
     * @param string $key Cache key with invalid characters
     * @return self
     */
    public static function invalidCharacters(string $key): self
    {
        return new self("Cache key '{$key}' contains invalid characters");
    }
}
