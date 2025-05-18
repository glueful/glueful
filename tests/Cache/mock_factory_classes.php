<?php

declare(strict_types=1);

namespace Glueful\Tests\Mocks;

/**
 * Mock implementation of CacheFactory for testing
 */
class MockCacheFactory
{
    /**
     * Create a cache driver instance
     *
     * @param string $driver Driver type
     * @param array $config Configuration options
     * @return mixed Driver instance
     */
    public static function createDriver(string $driver, array $config = [])
    {
        // For all tests, use a simple in-memory cache implementation
        return new MockMemoryCache();
    }
}

/**
 * In-memory cache implementation for testing
 */
class MockMemoryCache // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    private static $cache = [];
    public function get($key)
    {
        return self::$cache[$key] ?? null;
    }

    public function set($key, $value, $ttl = null)
    {
        self::$cache[$key] = $value;
        return true;
    }

    public function delete($key)
    {
        if (isset(self::$cache[$key])) {
            unset(self::$cache[$key]);
            return true;
        }
        return false;
    }

    public function clear()
    {
        self::$cache = [];
        return true;
    }

    public function has($key)
    {
        return isset(self::$cache[$key]);
    }

    public function zadd($key, $score, $value)
    {
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = [];
        }
        self::$cache[$key][$value] = $score;
        return 1;
    }

    public function zcard($key)
    {
        return count(self::$cache[$key] ?? []);
    }

    public function zrange($key, $start, $end)
    {
        if (!isset(self::$cache[$key])) {
            return [];
        }
        $members = array_keys(self::$cache[$key]);
        return array_slice($members, $start, $end - $start + 1);
    }

    public function zremrangebyscore($key, $min, $max)
    {
        if (!isset(self::$cache[$key])) {
            return 0;
        }

        $count = 0;
        foreach (self::$cache[$key] as $member => $score) {
            if ($score >= $min && $score <= $max) {
                unset(self::$cache[$key][$member]);
                $count++;
            }
        }

        return $count;
    }
}
