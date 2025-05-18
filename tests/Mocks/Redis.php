<?php

namespace Tests\Mocks;

/**
 * Mock Redis class for testing
 *
 * This class mocks the Redis extension to allow tests to run without requiring
 * the actual Redis extension to be installed.
 */

class Redis
{
    /** @var array Key-value storage */
    private array $data = [];

    /** @var bool Connection status */
    private bool $connected = false;

    /**
     * Connect to a Redis instance
     *
     * @param string $host Redis host
     * @param int $port Redis port
     * @param float $timeout Connection timeout
     * @return bool True if connection successful
     */
    public function connect(string $host, int $port = 6379, float $timeout = 0.0): bool
    {
        $this->connected = true;
        return true;
    }

    /**
     * Set authentication password
     *
     * @param string $password The password for authentication
     * @return bool True if authentication successful
     */
    public function auth(string $password): bool
    {
        return true;
    }

    /**
     * Select Redis database
     *
     * @param int $db The database number
     * @return bool True if database selected
     */
    public function select(int $db): bool
    {
        return true;
    }

    /**
     * Set a key's value
     *
     * @param string $key The key
     * @param string|int|float|bool|array $value The value to set
     * @return bool True if successful
     */
    public function set(string $key, $value): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    /**
     * Set a key's time to live in seconds
     *
     * @param string $key The key
     * @param int $seconds Time to live in seconds
     * @return bool True if successful
     */
    public function expire(string $key, int $seconds): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Get the value of a key
     *
     * @param string $key The key
     * @return mixed The value of the key, or false if the key doesn't exist
     */
    public function get(string $key)
    {
        return $this->data[$key] ?? false;
    }

    /**
     * Delete a key
     *
     * @param string|array $key The key(s) to delete
     * @return int Number of keys deleted
     */
    public function del($key): int
    {
        if (is_array($key)) {
            $count = 0;
            foreach ($key as $k) {
                if (isset($this->data[$k])) {
                    unset($this->data[$k]);
                    $count++;
                }
            }
            return $count;
        }

        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            return 1;
        }

        return 0;
    }

    /**
     * Check if a key exists
     *
     * @param string $key The key
     * @return bool True if the key exists
     */
    public function exists(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Add members to a sorted set
     *
     * @param string $key The sorted set key
     * @param float $score The score
     * @param string $member The member
     * @return int Number of elements added
     */
    public function zAdd(string $key, float $score, string $member): int
    {
        if (!isset($this->data[$key])) {
            $this->data[$key] = [];
        }

        if (!is_array($this->data[$key])) {
            $this->data[$key] = [];
        }

        $this->data[$key][$member] = $score;
        return 1;
    }

    /**
     * Get all members of a sorted set
     *
     * @param string $key The sorted set key
     * @param int $start The minimum score
     * @param int $end The maximum score
     * @return array Array of members
     */
    public function zRange(string $key, int $start, int $end): array
    {
        if (!isset($this->data[$key]) || !is_array($this->data[$key])) {
            return [];
        }

        $members = array_keys($this->data[$key]);
        if ($end < 0) {
            $end = count($members) + $end;
        }

        return array_slice($members, $start, $end - $start + 1);
    }

    /**
     * Count members in a sorted set
     *
     * @param string $key The sorted set key
     * @return int Number of members
     */
    public function zCard(string $key): int
    {
        if (!isset($this->data[$key]) || !is_array($this->data[$key])) {
            return 0;
        }

        return count($this->data[$key]);
    }

    /**
     * Remove members from a sorted set by score range
     *
     * @param string $key The sorted set key
     * @param float|string $min The minimum score
     * @param float|string $max The maximum score
     * @return int Number of members removed
     */
    public function zRemRangeByScore(string $key, $min, $max): int
    {
        if (!isset($this->data[$key]) || !is_array($this->data[$key])) {
            return 0;
        }

        $count = 0;
        $minScore = (float) $min;
        $maxScore = (float) $max;

        foreach ($this->data[$key] as $member => $score) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset($this->data[$key][$member]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all keys matching a pattern
     *
     * @param string $pattern The pattern
     * @return array Array of keys
     */
    public function keys(string $pattern): array
    {
        $pattern = str_replace('*', '.*', $pattern);
        $pattern = '/^' . $pattern . '$/';

        $result = [];
        foreach (array_keys($this->data) as $key) {
            if (preg_match($pattern, $key)) {
                $result[] = $key;
            }
        }

        return $result;
    }

    /**
     * Flush the database
     *
     * @return bool True if successful
     */
    public function flushDB(): bool
    {
        $this->data = [];
        return true;
    }

    /**
     * Close the connection
     *
     * @return bool True if successful
     */
    public function close(): bool
    {
        $this->connected = false;
        return true;
    }
}
