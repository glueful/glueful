<?php

namespace Glueful\Database\Tools;

class QueryProfilerService
{
    private $logger;
    private $threshold;
    private $sampling;
    private $profiles = [];

    public function __construct($threshold = null, $logger = null)
    {
        $this->logger = $logger ?? \Glueful\Logging\LogManager::getInstance();
        $this->threshold = $threshold ?? $this->getConfig('threshold', 100); // ms
        $this->sampling = $this->getConfig('sampling_rate', 1.0);
    }

    /**
     * Get a configuration value with a fallback default
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if configuration is not found
     * @return mixed Configuration value
     */
    private function getConfig(string $key, $default)
    {
        // Try to get from config if it exists
        if (function_exists('config')) {
            return config("database.profiler.{$key}", $default);
        }

        return $default;
    }

    /**
     * Profile a database query
     */
    public function profile(string $query, array $params, \Closure $executionCallback)
    {
        // Skip profiling based on sampling rate
        if (mt_rand(1, 100) / 100 > $this->sampling) {
            return $executionCallback();
        }

        $profile = [
            'id' => uniqid('query_'),
            'sql' => $query,
            'params' => $this->sanitizeParams($params),
            'start_time' => microtime(true),
            'memory_before' => memory_get_usage(),
            'backtrace' => $this->getBacktrace(),
        ];

        try {
            $result = $executionCallback();

            $profile['end_time'] = microtime(true);
            $profile['duration'] = ($profile['end_time'] - $profile['start_time']) * 1000; // ms
            $profile['memory_after'] = memory_get_usage();
            $profile['memory_delta'] = $profile['memory_after'] - $profile['memory_before'];
            $profile['row_count'] = is_countable($result) ? count($result) : null;
            $profile['status'] = 'success';

            $this->recordProfile($profile);

            if ($profile['duration'] > $this->threshold) {
                $this->logSlowQuery($profile);
            }

            return $result;
        } catch (\Throwable $e) {
            $profile['end_time'] = microtime(true);
            $profile['duration'] = ($profile['end_time'] - $profile['start_time']) * 1000; // ms
            $profile['status'] = 'error';
            $profile['error'] = $e->getMessage();

            $this->recordProfile($profile);

            throw $e;
        }
    }

    /**
     * Record a query profile for storage and analysis
     */
    private function recordProfile(array $profile): void
    {
        $this->profiles[] = $profile;

        // Limit the number of stored profiles to avoid memory issues
        if (count($this->profiles) > config('database.profiler.max_profiles', 100)) {
            array_shift($this->profiles);
        }
    }

    /**
     * Sanitize query parameters for safe logging
     */
    private function sanitizeParams(array $params): array
    {
        $sanitized = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && strlen($value) > 100) {
                $sanitized[$key] = substr($value, 0, 100) . '...';
            } elseif (is_object($value)) {
                $sanitized[$key] = get_class($value) . ' (object)';
            } elseif (is_resource($value)) {
                $sanitized[$key] = get_resource_type($value) . ' (resource)';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get backtrace information for the query
     */
    private function getBacktrace(): array
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $relevantTrace = [];

        // Filter out framework internal calls
        foreach ($backtrace as $trace) {
            if (!isset($trace['file'])) {
                continue;
            }

            // Skip internal framework files
            if (
                strpos($trace['file'], 'vendor/glueful') !== false ||
                strpos($trace['file'], 'api/Database/') !== false
            ) {
                continue;
            }

            $relevantTrace[] = [
                'file' => $trace['file'],
                'line' => $trace['line'] ?? null,
                'function' => $trace['function'],
                'class' => $trace['class'] ?? null,
            ];

            // Only include a few frames for brevity
            if (count($relevantTrace) >= 3) {
                break;
            }
        }

        return $relevantTrace;
    }
       /**
     * Log a slow query to the system logger
     */
    private function logSlowQuery(array $profile): void
    {
        $context = [
            'query_id' => $profile['id'],
            'duration' => $profile['duration'],
            'memory_delta' => $profile['memory_delta'],
            'sql' => $profile['sql'],
            'params' => $profile['params'],
        ];

        if (isset($profile['row_count'])) {
            $context['row_count'] = $profile['row_count'];
        }

        // Use channel method if available, otherwise use warning directly
        if (method_exists($this->logger, 'channel')) {
            $this->logger->channel('database')
                ->warning("Slow query detected ({$profile['duration']}ms): {$profile['sql']}", $context);
        } else {
            $this->logger->warning("Slow query detected ({$profile['duration']}ms): {$profile['sql']}", $context);
        }
    }

    /**
     * Get all recorded query profiles
     */
    public function getProfiles(): array
    {
        return $this->profiles;
    }

    /**
     * Get recent query profiles with optional filtering
     */
    public function getRecentProfiles(int $limit = 100, ?float $thresholdMs = null): array
    {
        if ($thresholdMs === null) {
            return array_slice(array_reverse($this->profiles), 0, $limit);
        }

        $filtered = array_filter($this->profiles, function ($profile) use ($thresholdMs) {
            return $profile['duration'] >= $thresholdMs;
        });

        return array_slice(array_reverse($filtered), 0, $limit);
    }

    /**
     * Clear all stored profiles
     */
    public function clearProfiles(): void
    {
        $this->profiles = [];
    }

    /**
     * Get performance statistics about executed queries
     */
    public function getStatistics(): array
    {
        if (empty($this->profiles)) {
            return [
                'count' => 0,
                'total_duration' => 0,
                'avg_duration' => 0,
                'max_duration' => 0,
                'slow_queries' => 0,
            ];
        }

        $count = count($this->profiles);
        $totalDuration = 0;
        $maxDuration = 0;
        $slowQueries = 0;

        foreach ($this->profiles as $profile) {
            $totalDuration += $profile['duration'];
            $maxDuration = max($maxDuration, $profile['duration']);

            if ($profile['duration'] > $this->threshold) {
                $slowQueries++;
            }
        }

        return [
            'count' => $count,
            'total_duration' => $totalDuration,
            'avg_duration' => $totalDuration / $count,
            'max_duration' => $maxDuration,
            'slow_queries' => $slowQueries,
            'slow_percentage' => ($slowQueries / $count) * 100,
        ];
    }
}
