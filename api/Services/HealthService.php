<?php

namespace Glueful\Services;

use Glueful\Cache\CacheStore;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Helpers\CacheHelper;

/**
 * Health Service
 *
 * Provides shared health check functionality for both CLI and HTTP endpoints.
 * All checks use the framework's abstraction layers (QueryBuilder, CacheStore)
 * to ensure consistent behavior across different database and cache drivers.
 */
class HealthService
{
    use DatabaseConnectionTrait;

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var CacheStore|null Cache driver instance */
    private ?CacheStore $cache = null;

    /**
     * Constructor
     */
    public function __construct(?CacheStore $cache = null)
    {
        $this->cache = $cache ?? CacheHelper::createCacheInstance();
        if ($this->cache === null) {
            throw new \RuntimeException(
                'CacheStore is required for HealthService: Unable to create cache instance.'
            );
        }
    }

    /**
     * Get singleton instance
     */
    private static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Check database connectivity and functionality using QueryBuilder abstraction
     */
    public static function checkDatabase(): array
    {
        return self::getInstance()->performDatabaseCheck();
    }

    /**
     * Perform database health check using trait's shared connection
     */
    private function performDatabaseCheck(): array
    {
        try {
            $connection = $this->getConnection();
            $queryBuilder = $this->getQueryBuilder();

            // Test 1: Basic connectivity with QueryBuilder raw query
            $testResult = $queryBuilder->rawQuery('SELECT 1 as test');

            // Test 2: Check if migrations table exists and is accessible
            $migrationCount = $queryBuilder->count('migrations');

            return [
                'status' => 'ok',
                'message' => 'Database connection and QueryBuilder operational',
                'driver' => $connection->getDriverName(),
                'migrations_applied' => $migrationCount,
                'connectivity_test' => !empty($testResult)
            ];
        } catch (\PDOException $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'type' => 'connection_error'
            ];
        } catch (\Exception $e) {
            // Check if it's a table not found error (migrations not run)
            if (
                str_contains($e->getMessage(), 'migrations') &&
                (str_contains($e->getMessage(), "doesn't exist") ||
                 str_contains($e->getMessage(), 'does not exist') ||
                 str_contains($e->getMessage(), 'no such table'))
            ) {
                return [
                    'status' => 'warning',
                    'message' => 'Database connected but migrations not run',
                    'suggestion' => 'Run: php glueful migrate run'
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage(),
                'type' => 'query_error'
            ];
        }
    }

    /**
     * Check cache connectivity and functionality
     */
    public static function checkCache(): array
    {
        return self::getInstance()->performCacheCheck();
    }

    /**
     * Perform cache health check using injected cache driver
     */
    private function performCacheCheck(): array
    {
        try {
            // Test cache write/read/delete operations
            $testKey = 'health_check_' . time();
            $testValue = 'health_test_' . uniqid();

            $this->cache->set($testKey, $testValue, 60);
            $retrieved = $this->cache->get($testKey);
            $this->cache->delete($testKey);

            if ($retrieved === $testValue) {
                return [
                    'status' => 'ok',
                    'message' => 'Cache is working properly',
                    'driver' => config('cache.default', 'unknown'),
                    'operations' => 'read/write/delete functional'
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Cache read/write test failed',
                    'expected' => $testValue,
                    'received' => $retrieved
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Cache error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check required PHP extensions
     */
    public static function checkExtensions(): array
    {
        $required = ['pdo', 'json', 'mbstring', 'openssl'];
        $missing = [];
        $loaded = [];

        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            } else {
                $loaded[] = $extension;
            }
        }

        if (empty($missing)) {
            return [
                'status' => 'ok',
                'message' => 'All required extensions are loaded',
                'loaded' => $loaded
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Missing required extensions: ' . implode(', ', $missing),
                'missing' => $missing,
                'loaded' => $loaded
            ];
        }
    }

    /**
     * Check application configuration
     */
    public static function checkConfiguration(): array
    {
        $issues = [];
        $warnings = [];

        // Critical configuration checks
        if (empty(env('JWT_KEY')) || env('JWT_KEY') === 'your-secure-jwt-key-here') {
            $issues[] = 'JWT_KEY not properly configured';
        }

        if (empty(env('APP_KEY')) || env('APP_KEY') === 'generate-secure-32-char-key-here') {
            $issues[] = 'APP_KEY not properly configured';
        }

        // Check .env file exists
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envPath)) {
            $issues[] = '.env file not found';
        }

        // Integrate production environment validation
        if (env('APP_ENV') === 'production') {
            $prodValidation = \Glueful\Security\SecurityManager::validateProductionEnvironment();

            // Convert production warnings to health check issues
            foreach ($prodValidation['warnings'] as $warning) {
                $issues[] = "Production: $warning";
            }

            // Convert production recommendations to health check warnings
            foreach ($prodValidation['recommendations'] as $recommendation) {
                $warnings[] = "Production: $recommendation";
            }
        }

        if (!empty($issues)) {
            return [
                'status' => 'error',
                'message' => 'Critical configuration issues detected',
                'issues' => $issues,
                'warnings' => $warnings
            ];
        } elseif (!empty($warnings)) {
            return [
                'status' => 'warning',
                'message' => 'Configuration warnings detected',
                'warnings' => $warnings
            ];
        } else {
            return [
                'status' => 'ok',
                'message' => 'Configuration is valid',
                'environment' => env('APP_ENV', 'unknown')
            ];
        }
    }

    /**
     * Get overall system health status
     */
    public static function getOverallHealth(): array
    {
        $checks = [
            'database' => self::checkDatabase(),
            'cache' => self::checkCache(),
            'extensions' => self::checkExtensions(),
            'config' => self::checkConfiguration()
        ];

        // Determine overall status
        $hasErrors = false;
        $hasWarnings = false;

        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $hasErrors = true;
            } elseif ($check['status'] === 'warning') {
                $hasWarnings = true;
            }
        }

        $overallStatus = 'ok';
        if ($hasErrors) {
            $overallStatus = 'error';
        } elseif ($hasWarnings) {
            $overallStatus = 'warning';
        }

        return [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'version' => config('app.version', '1.0.0'),
            'environment' => env('APP_ENV', 'unknown'),
            'checks' => $checks
        ];
    }

    /**
     * Convert health service result to SystemCheckCommand format
     */
    public static function convertToSystemCheckFormat(array $healthResult): array
    {
        $passed = $healthResult['status'] === 'ok';
        $details = [];

        // Add details from various health result fields
        if (isset($healthResult['driver'])) {
            $details[] = 'Driver: ' . $healthResult['driver'];
        }

        if (isset($healthResult['migrations_applied'])) {
            $details[] = 'Migrations applied: ' . $healthResult['migrations_applied'];
        }

        if (isset($healthResult['loaded'])) {
            $details[] = 'Loaded extensions: ' . implode(', ', $healthResult['loaded']);
        }

        if (isset($healthResult['missing'])) {
            $details = array_merge($details, array_map(fn($ext) => "Missing: $ext", $healthResult['missing']));
        }

        if (isset($healthResult['issues'])) {
            $details = array_merge($details, $healthResult['issues']);
        }

        if (isset($healthResult['warnings'])) {
            $details = array_merge($details, array_map(fn($w) => "Warning: $w", $healthResult['warnings']));
        }

        if (isset($healthResult['suggestion'])) {
            $details[] = 'Suggestion: ' . $healthResult['suggestion'];
        }

        return [
            'passed' => $passed,
            'message' => $healthResult['message'],
            'details' => $details
        ];
    }
}
