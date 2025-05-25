<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Services\HealthService;
use Glueful\Exceptions\DatabaseException;

/**
 * Database Connection Validator
 *
 * Provides startup connection validation and graceful degradation capabilities.
 * Prevents silent database failures by validating connectivity during framework bootstrap.
 */
class ConnectionValidator
{
    /** @var bool Whether connection validation is enabled */
    private static bool $validationEnabled = true;

    /** @var bool Whether graceful degradation is enabled */
    private static bool $gracefulDegradationEnabled = true;

    /** @var bool Current database availability status */
    private static bool $databaseAvailable = true;

    /** @var array Connection validation cache */
    private static array $validationCache = [];

    /**
     * Validate database connection on startup
     *
     * @param bool $throwOnFailure Whether to throw exception on failure
     * @return bool True if database is available
     * @throws DatabaseException If validation fails and throwOnFailure is true
     */
    public static function validateOnStartup(bool $throwOnFailure = false): bool
    {
        if (!self::$validationEnabled) {
            return true;
        }

        // Check cache first (valid for 30 seconds)
        $cacheKey = 'startup_validation';
        if (isset(self::$validationCache[$cacheKey])) {
            $cached = self::$validationCache[$cacheKey];
            if (time() - $cached['timestamp'] < 30) {
                self::$databaseAvailable = $cached['available'];
                return $cached['available'];
            }
        }

        $isAvailable = false;
        $healthData = null;
        $errorMessage = null;

        try {
            $health = HealthService::checkDatabase();
            $isAvailable = $health['status'] === 'ok';
            $healthData = $health;

            // Cache successful result
            self::$validationCache[$cacheKey] = [
                'available' => $isAvailable,
                'timestamp' => time(),
                'details' => $health
            ];

            if (!$isAvailable) {
                $errorMessage = "Database connection validation failed: {$health['message']}";
            }
        } catch (\Exception $e) {
            $isAvailable = false;
            $errorMessage = "Database connection validation error: " . $e->getMessage();
            $healthData = ['status' => 'error', 'message' => $e->getMessage()];

            // Cache failure result
            self::$validationCache[$cacheKey] = [
                'available' => false,
                'timestamp' => time(),
                'error' => $e->getMessage()
            ];
        }

        // Update availability status
        self::$databaseAvailable = $isAvailable;

        // Handle validation failure
        if (!$isAvailable && $errorMessage !== null) {
            error_log($errorMessage);

            if ($throwOnFailure) {
                throw new DatabaseException($errorMessage);
            }

            // Enable graceful degradation if not throwing
            if (self::$gracefulDegradationEnabled && $healthData !== null) {
                self::enableGracefulDegradation($healthData);
            }
        }

        return $isAvailable;
    }

    /**
     * Check if database is currently available
     */
    public static function isDatabaseAvailable(): bool
    {
        return self::$databaseAvailable;
    }

    /**
     * Enable graceful degradation mode
     */
    private static function enableGracefulDegradation(array $healthData): void
    {
        // Set environment flag for graceful degradation
        $_ENV['GRACEFUL_DEGRADATION_MODE'] = 'true';

        // Log degradation activation
        error_log("Graceful degradation mode activated due to database unavailability");

        // Notify monitoring systems
        error_log(json_encode([
            'event' => 'graceful_degradation_activated',
            'reason' => 'database_unavailable',
            'details' => $healthData,
            'timestamp' => date('c')
        ]));
    }

    /**
     * Perform periodic connection health check
     */
    public static function performHealthCheck(): array
    {
        $startTime = microtime(true);

        try {
            $health = HealthService::checkDatabase();
            $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

            $result = [
                'status' => $health['status'],
                'available' => $health['status'] === 'ok',
                'response_time_ms' => round($responseTime, 2),
                'timestamp' => date('c'),
                'details' => $health
            ];

            // Update availability status
            self::$databaseAvailable = $result['available'];

            // Log slow connections
            if ($responseTime > 1000) { // Over 1 second
                error_log("Slow database connection detected: {$responseTime}ms");
            }

            return $result;
        } catch (\Exception $e) {
            self::$databaseAvailable = false;

            return [
                'status' => 'error',
                'available' => false,
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'timestamp' => date('c'),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Configure validation settings
     */
    public static function configure(array $options): void
    {
        if (isset($options['enabled'])) {
            self::$validationEnabled = (bool) $options['enabled'];
        }

        if (isset($options['graceful_degradation'])) {
            self::$gracefulDegradationEnabled = (bool) $options['graceful_degradation'];
        }
    }

    /**
     * Get validation configuration
     */
    public static function getConfiguration(): array
    {
        return [
            'validation_enabled' => self::$validationEnabled,
            'graceful_degradation_enabled' => self::$gracefulDegradationEnabled,
            'database_available' => self::$databaseAvailable
        ];
    }

    /**
     * Reset validation state (useful for testing)
     */
    public static function reset(): void
    {
        self::$validationCache = [];
        self::$databaseAvailable = true;
        unset($_ENV['GRACEFUL_DEGRADATION_MODE']);
    }

    /**
     * Check if application is in graceful degradation mode
     */
    public static function isInGracefulDegradationMode(): bool
    {
        return isset($_ENV['GRACEFUL_DEGRADATION_MODE']) && $_ENV['GRACEFUL_DEGRADATION_MODE'] === 'true';
    }
}
