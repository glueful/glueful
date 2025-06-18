<?php

declare(strict_types=1);

namespace Glueful\Database;

use PDO;
use Glueful\Database\Exceptions\ConnectionPoolException;
use Glueful\Database\PooledConnection;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Exceptions\DatabaseException;
use Glueful\Exceptions\BusinessLogicException;

/**
 * ConnectionPool
 *
 * Manages a pool of database connections with automatic lifecycle management,
 * health checking, and performance optimization.
 *
 * Features:
 * - Dynamic pool sizing with min/max bounds
 * - Connection health monitoring
 * - Automatic recycling of stale connections
 * - Acquisition timeout handling
 * - Comprehensive statistics tracking
 * - Thread-safe operations
 *
 * @package Glueful\Database
 */
class ConnectionPool
{
    /** @var array Available connections ready for use */
    private array $availableConnections = [];

    /** @var array Currently active connections */
    private array $activeConnections = [];

    /** @var array Pool configuration */
    private array $config;

    /** @var string Database connection string */
    private string $dsn;

    /** @var string|null Database username */
    private ?string $username;

    /** @var string|null Database password */
    private ?string $password;

    /** @var array PDO connection options */
    private array $options;

    /** @var int Counter for generating unique connection IDs */
    private int $connectionIdCounter = 0;

    /** @var float Last maintenance run timestamp */
    private float $lastMaintenanceRun = 0;

    /** @var DatabaseDriver Database driver */
    private DatabaseDriver $driver;

    /** @var mixed Maintenance timer handle (ReactPHP/Swoole) */
    private $maintenanceTimer = null;

    /** @var bool Whether maintenance worker is running */
    private bool $maintenanceWorkerRunning = false;

    /** @var array Pool statistics */
    private array $stats = [
        'total_created' => 0,
        'total_destroyed' => 0,
        'total_acquisitions' => 0,
        'total_releases' => 0,
        'total_timeouts' => 0,
        'peak_active' => 0,
        'total_health_checks' => 0,
        'failed_health_checks' => 0,
        'maintenance_runs' => 0,
        'maintenance_failures' => 0
    ];

    /**
     * Initialize connection pool
     *
     * @param array $config Pool configuration
     * @param string $dsn Database connection string
     * @param string|null $username Database username
     * @param string|null $password Database password
     * @param array $options PDO options
     * @param DatabaseDriver $driver Database driver
     */
    public function __construct(
        array $config,
        string $dsn,
        ?string $username,
        ?string $password,
        array $options,
        DatabaseDriver $driver
    ) {
        $this->config = [
            'min_connections' => $config['min_connections'] ?? 2,
            'max_connections' => $config['max_connections'] ?? 10,
            'idle_timeout' => $config['idle_timeout'] ?? 300, // 5 minutes
            'max_lifetime' => $config['max_lifetime'] ?? 3600, // 1 hour
            'acquisition_timeout' => $config['acquisition_timeout'] ?? 30,
            'health_check_interval' => $config['health_check_interval'] ?? 60,
            'health_check_timeout' => $config['health_check_timeout'] ?? 5,
            'max_use_count' => $config['max_use_count'] ?? 1000,
            'retry_attempts' => $config['retry_attempts'] ?? 3,
            'retry_delay' => $config['retry_delay'] ?? 100 // milliseconds
        ];

        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
        $this->driver = $driver;

        // Initialize minimum connections
        $this->initializePool();

        // Start background maintenance
        $this->startMaintenanceWorker();
    }

    /**
     * Acquire a connection from the pool
     *
     * @return PooledConnection
     * @throws ConnectionPoolException If acquisition timeout is reached
     */
    public function acquire(): PooledConnection
    {
        $startTime = microtime(true);
        $attempts = 0;

        while (true) {
            // Try to get available connection
            if (!empty($this->availableConnections)) {
                $connection = array_shift($this->availableConnections);

                // Validate connection health
                if ($this->isConnectionHealthy($connection)) {
                    $this->activeConnections[$connection->getId()] = $connection;
                    $this->stats['total_acquisitions']++;
                    $this->updatePeakActive();
                    return $connection;
                } else {
                    $this->destroyConnection($connection);
                }
            }

            // Create new connection if under limit
            if ($this->getTotalConnections() < $this->config['max_connections']) {
                try {
                    $connection = $this->createConnection();
                    $this->activeConnections[$connection->getId()] = $connection;
                    $this->stats['total_acquisitions']++;
                    $this->updatePeakActive();
                    return $connection;
                } catch (\Exception $e) {
                    $attempts++;
                    if ($attempts >= $this->config['retry_attempts']) {
                        throw new ConnectionPoolException(
                            'Failed to create connection after ' . $attempts . ' attempts: ' . $e->getMessage()
                        );
                    }
                    usleep($this->config['retry_delay'] * 1000);
                    continue;
                }
            }

            // Check timeout
            if ((microtime(true) - $startTime) > $this->config['acquisition_timeout']) {
                $this->stats['total_timeouts']++;
                throw new ConnectionPoolException(sprintf(
                    'Connection acquisition timeout after %.2f seconds. Pool state: %d active, %d available',
                    microtime(true) - $startTime,
                    count($this->activeConnections),
                    count($this->availableConnections)
                ));
            }

            // Perform maintenance if needed
            $this->performMaintenanceIfNeeded();

            // Wait briefly before retrying
            usleep(10000); // 10ms
        }
    }

    /**
     * Release a connection back to the pool
     *
     * @param PooledConnection $connection Connection to release
     * @return void
     */
    public function release(PooledConnection $connection): void
    {
        $connectionId = $connection->getId();

        // Remove from active connections
        unset($this->activeConnections[$connectionId]);

        // Check if connection should be recycled
        if ($this->shouldRecycleConnection($connection)) {
            $this->destroyConnection($connection);
            return;
        }

        // Return to available pool
        $connection->markIdle();
        $this->availableConnections[] = $connection;
        $this->stats['total_releases']++;
    }

    /**
     * Initialize the pool with minimum connections
     *
     * @return void
     */
    private function initializePool(): void
    {
        for ($i = 0; $i < $this->config['min_connections']; $i++) {
            try {
                $this->availableConnections[] = $this->createConnection();
            } catch (\Exception $e) {
                // Log initialization failure but continue
                error_log('Failed to initialize connection: ' . $e->getMessage());
            }
        }
    }

    /**
     * Create a new pooled connection
     *
     * @return PooledConnection
     * @throws \Exception If connection creation fails
     */
    private function createConnection(): PooledConnection
    {
        try {
            $pdo = new PDO($this->dsn, $this->username, $this->password, $this->options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            // Add debugging information for connection failures
            $debugInfo = sprintf(
                'Connection failed - DSN: %s, Username: %s, Password: %s',
                $this->dsn,
                $this->username ? 'SET' : 'NULL',
                $this->password ? 'SET' : 'NULL'
            );
            error_log('ConnectionPool Debug: ' . $debugInfo);
            throw $e;
        }

        $connection = new PooledConnection($pdo, $this, 'conn_' . ++$this->connectionIdCounter);
        $this->stats['total_created']++;

        return $connection;
    }

    /**
     * Check if a connection is healthy
     *
     * Performs comprehensive health checks including:
     * - Database connectivity test
     * - Connection age verification
     * - Transaction state validation
     * - Database-specific ping queries
     *
     * @param PooledConnection $connection
     * @return bool
     */
    private function isConnectionHealthy(PooledConnection $connection): bool
    {
        $this->stats['total_health_checks']++;

        try {
            // Check if connection is marked as unhealthy
            if (!$connection->isHealthy()) {
                $this->stats['failed_health_checks']++;
                return false;
            }

            // Check if connection age exceeds maximum lifetime
            if ($connection->getAge() > $this->config['max_lifetime']) {
                $this->stats['failed_health_checks']++;
                return false;
            }

            // Check if connection is in a broken transaction state
            if ($connection->isInTransaction()) {
                // Allow transactions but verify they're still valid
                $inTransaction = $this->verifyTransactionState($connection);
                if (!$inTransaction) {
                    $this->stats['failed_health_checks']++;
                    return false;
                }
            }

            // Perform database connectivity test
            if (!$this->performConnectivityTest($connection)) {
                $this->stats['failed_health_checks']++;
                return false;
            }

            // Additional health checks based on connection usage
            return $this->performAdvancedHealthChecks($connection);
        } catch (\Exception $e) {
            $this->stats['failed_health_checks']++;
            error_log('Health check failed for connection ' . $connection->getId() . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Perform database connectivity test with engine-specific queries
     *
     * @param PooledConnection $connection
     * @return bool
     */
    private function performConnectivityTest(PooledConnection $connection): bool
    {
        try {
            // Use database-specific ping query for better reliability
            $pingQuery = $this->driver->getPingQuery();
            $result = $connection->query($pingQuery);

            if ($result === false) {
                return false;
            }

            // For SELECT queries, ensure we can fetch the result
            if (stripos($pingQuery, 'SELECT') === 0) {
                $row = $result->fetch();
                return $row !== false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verify transaction state is valid
     *
     * @param PooledConnection $connection
     * @return bool
     */
    private function verifyTransactionState(PooledConnection $connection): bool
    {
        try {
            // Check if PDO is null (connection destroyed)
            $pdo = $connection->getPDO();
            if ($pdo === null) {
                return false;
            }

            // Check if the transaction is still active on the database side
            $inTransaction = $pdo->inTransaction();

            // If the connection thinks it's in transaction but PDO says no, there's a mismatch
            if ($connection->isInTransaction() && !$inTransaction) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Perform advanced health checks based on connection usage patterns
     *
     * @param PooledConnection $connection
     * @return bool
     */
    private function performAdvancedHealthChecks(PooledConnection $connection): bool
    {
        $stats = $connection->getStats();

        // Check if connection has been used excessively
        $maxUseCount = $this->config['max_use_count'] ?? 1000;
        if ($stats['use_count'] > $maxUseCount) {
            return false;
        }

        // Check for idle time in active connections (potential leaked connections)
        if ($connection->getIdleTime() > ($this->config['idle_timeout'] * 2)) {
            return false;
        }

        // Verify connection is still responsive with a timeout
        return $this->performTimeoutTest($connection);
    }

    /**
     * Perform connectivity test with timeout
     *
     * @param PooledConnection $connection
     * @return bool
     */
    private function performTimeoutTest(PooledConnection $connection): bool
    {
        try {
            $pdo = $connection->getPDO();

            // Check if PDO is null (connection destroyed)
            if ($pdo === null) {
                return false;
            }

            // Set a short timeout for health check
            $originalTimeout = $pdo->getAttribute(\PDO::ATTR_TIMEOUT);
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5); // 5 second timeout

            // Perform a lightweight query
            $result = $pdo->query('SELECT 1');

            // Restore original timeout
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, $originalTimeout);

            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine if a connection should be recycled
     *
     * @param PooledConnection $connection
     * @return bool
     */
    private function shouldRecycleConnection(PooledConnection $connection): bool
    {
        // Check age
        if ($connection->getAge() > $this->config['max_lifetime']) {
            return true;
        }

        // Check idle time
        if ($connection->getIdleTime() > $this->config['idle_timeout']) {
            return true;
        }

        // Check if connection is still healthy
        if (!$this->isConnectionHealthy($connection)) {
            return true;
        }

        return false;
    }

    /**
     * Destroy a connection and remove it from the pool
     *
     * @param PooledConnection $connection
     * @return void
     */
    private function destroyConnection(PooledConnection $connection): void
    {
        try {
            $connection->destroy();
        } catch (\Exception $e) {
            // Log destruction failure
            error_log('Failed to destroy connection: ' . $e->getMessage());
        }

        $this->stats['total_destroyed']++;
    }

    /**
     * Get total number of connections in the pool
     *
     * @return int
     */
    private function getTotalConnections(): int
    {
        return count($this->availableConnections) + count($this->activeConnections);
    }

    /**
     * Update peak active connections statistic
     *
     * @return void
     */
    private function updatePeakActive(): void
    {
        $active = count($this->activeConnections);
        if ($active > $this->stats['peak_active']) {
            $this->stats['peak_active'] = $active;
        }
    }

    /**
     * Start background maintenance worker
     *
     * @return void
     */
    private function startMaintenanceWorker(): void
    {
        // Register shutdown handler for cleanup
        register_shutdown_function([$this, 'shutdown']);

        // In CLI/long-running process context
        if (php_sapi_name() === 'cli') {
            $this->startAsyncMaintenanceWorker();
        }

        // For web requests, maintenance runs on-demand
        $this->lastMaintenanceRun = microtime(true);
    }

    /**
     * Start asynchronous maintenance worker for CLI/long-running processes
     *
     * @return void
     */
    private function startAsyncMaintenanceWorker(): void
    {
        if ($this->maintenanceWorkerRunning) {
            return;
        }

        // Check for ReactPHP event loop
        if (class_exists('React\\EventLoop\\Factory') || class_exists('React\\EventLoop\\Loop')) {
            $this->startReactPHPMaintenanceWorker();
            return;
        }

        // Check for Swoole
        $swooleTimerClass = 'Swoole' . '\\' . 'Timer';
        if (extension_loaded('swoole') && class_exists($swooleTimerClass)) {
            $this->startTimerMaintenanceWorker();
            return;
        }

        // Check for pcntl_fork support
        if (function_exists('pcntl_fork') && function_exists('pcntl_wait')) {
            $this->startForkMaintenanceWorker();
            return;
        }

        // Fallback to signal-based maintenance for CLI
        $this->startSignalMaintenanceWorker();
    }

    /**
     * Start ReactPHP-based maintenance worker
     *
     * @return void
     */
    private function startReactPHPMaintenanceWorker(): void
    {
        try {
            $loop = null;

            // Try modern ReactPHP Loop::get() method (v1.2+)
            if (class_exists('React\\EventLoop\\Loop')) {
                $loopClass = 'React\\EventLoop\\Loop';
                try {
                    $loop = call_user_func([$loopClass, 'get']);
                } catch (\Error $e) {
                    // Method doesn't exist, try factory
                    $loop = null;
                }
            }

            // Fallback to Factory for older versions (v1.0-v1.1)
            if (!$loop && class_exists('React\\EventLoop\\Factory')) {
                $factoryClass = 'React\\EventLoop\\Factory';
                $loop = call_user_func([$factoryClass, 'create']);
            }

            if ($loop) {
                $this->maintenanceTimer = $loop->addPeriodicTimer(
                    $this->config['health_check_interval'],
                    function () {
                        $this->performMaintenanceWithErrorHandling();
                    }
                );
                $this->maintenanceWorkerRunning = true;
            } else {
                throw BusinessLogicException::operationNotAllowed(
                    'maintenance_worker',
                    'ReactPHP event loop not available'
                );
            }
        } catch (\Exception $e) {
            error_log('Failed to start ReactPHP maintenance worker: ' . $e->getMessage());
        }
    }

    /**
     * Start timer-based maintenance worker
     *
     * Uses dynamic class loading to avoid compile-time dependencies.
     * Safely calls timer functions without requiring specific extensions.
     *
     * @return void
     */
    private function startTimerMaintenanceWorker(): void
    {
        if (!extension_loaded('swoole')) {
            error_log('Timer extension not loaded');
            return;
        }

        $timerClassName = 'Swoole' . '\\' . 'Timer';

        if (!class_exists($timerClassName)) {
            error_log('Timer class not available');
            return;
        }

        try {
            $this->maintenanceTimer = call_user_func(
                [$timerClassName, 'tick'],
                $this->config['health_check_interval'] * 1000, // Convert to milliseconds
                function () {
                    $this->performMaintenanceWithErrorHandling();
                }
            );
            $this->maintenanceWorkerRunning = true;
        } catch (\Exception $e) {
            error_log('Failed to start timer maintenance worker: ' . $e->getMessage());
        } catch (\Error $e) {
            error_log('Timer method not available: ' . $e->getMessage());
        }
    }

    /**
     * Start fork-based maintenance worker
     *
     * @return void
     */
    private function startForkMaintenanceWorker(): void
    {
        try {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw DatabaseException::connectionFailed(
                    'Failed to fork maintenance process'
                );
            } elseif ($pid === 0) {
                // Child process - run maintenance loop
                $this->runMaintenanceLoop();
                exit(0);
            } else {
                // Parent process - store child PID for cleanup
                $this->maintenanceWorkerRunning = true;

                // Register signal handler to clean up child process
                pcntl_signal(SIGTERM, function () use ($pid) {
                    posix_kill($pid, SIGTERM);
                    pcntl_wait($status);
                });
            }
        } catch (\Exception $e) {
            error_log('Failed to start fork maintenance worker: ' . $e->getMessage());
        }
    }

    /**
     * Start signal-based maintenance worker (fallback)
     *
     * @return void
     */
    private function startSignalMaintenanceWorker(): void
    {
        try {
            // Use SIGALRM for periodic maintenance
            if (function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
                pcntl_signal(SIGALRM, function () {
                    $this->performMaintenanceWithErrorHandling();
                    // Re-schedule next maintenance
                    pcntl_alarm($this->config['health_check_interval']);
                });

                // Start the alarm
                pcntl_alarm($this->config['health_check_interval']);
                $this->maintenanceWorkerRunning = true;
            }
        } catch (\Exception $e) {
            error_log('Failed to start signal maintenance worker: ' . $e->getMessage());
        }
    }

    /**
     * Run maintenance loop for forked process
     *
     * @return void
     */
    private function runMaintenanceLoop(): void
    {
        while (true) {
            $this->performMaintenanceWithErrorHandling();
            sleep($this->config['health_check_interval']);

            // Check for parent process termination
            if (posix_getppid() === 1) {
                // Parent process has terminated
                break;
            }
        }
    }

    /**
     * Perform maintenance with comprehensive error handling
     *
     * @return void
     */
    private function performMaintenanceWithErrorHandling(): void
    {
        try {
            $this->performMaintenance();
            $this->stats['maintenance_runs']++;
        } catch (\Exception $e) {
            $this->stats['maintenance_failures']++;
            error_log('Pool maintenance failed: ' . $e->getMessage());

            // If too many failures, disable maintenance worker
            if ($this->stats['maintenance_failures'] > 10) {
                $this->stopMaintenanceWorker();
                error_log('Pool maintenance worker disabled due to excessive failures');
            }
        }
    }

    /**
     * Stop the maintenance worker
     *
     * @return void
     */
    private function stopMaintenanceWorker(): void
    {
        if (!$this->maintenanceWorkerRunning) {
            return;
        }

        try {
            if ($this->maintenanceTimer) {
                // ReactPHP timer
                if (method_exists($this->maintenanceTimer, 'cancel')) {
                    $this->maintenanceTimer->cancel();
                } elseif (extension_loaded('swoole') && is_int($this->maintenanceTimer)) {
                    // Swoole timer
                    $timerClass = 'Swoole\\Timer';
                    call_user_func([$timerClass, 'clear'], $this->maintenanceTimer);
                }

                $this->maintenanceTimer = null;
            }

            // Cancel signal-based maintenance
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            $this->maintenanceWorkerRunning = false;
        } catch (\Exception $e) {
            error_log('Failed to stop maintenance worker: ' . $e->getMessage());
        }
    }

    /**
     * Perform maintenance if needed
     *
     * @return void
     */
    private function performMaintenanceIfNeeded(): void
    {
        $now = microtime(true);
        if (($now - $this->lastMaintenanceRun) < $this->config['health_check_interval']) {
            return;
        }

        $this->performMaintenance();
        $this->lastMaintenanceRun = $now;
    }

    /**
     * Perform pool maintenance
     *
     * @return void
     */
    private function performMaintenance(): void
    {
        // Remove idle connections exceeding timeout
        $this->pruneIdleConnections();

        // Replace connections exceeding max lifetime
        $this->recycleOldConnections();

        // Ensure minimum connections
        $this->ensureMinimumConnections();

        // Health check active connections
        $this->healthCheckConnections();

        // Update statistics
        $this->updatePoolStatistics();
    }

    /**
     * Remove idle connections that have exceeded timeout
     *
     * @return void
     */
    private function pruneIdleConnections(): void
    {
        $pruned = [];

        foreach ($this->availableConnections as $key => $connection) {
            if ($connection->getIdleTime() > $this->config['idle_timeout']) {
                $pruned[] = $key;
                $this->destroyConnection($connection);
            }
        }

        // Remove pruned connections
        foreach (array_reverse($pruned) as $key) {
            unset($this->availableConnections[$key]);
        }

        // Re-index array
        $this->availableConnections = array_values($this->availableConnections);
    }

    /**
     * Recycle connections that have exceeded max lifetime
     *
     * @return void
     */
    private function recycleOldConnections(): void
    {
        $recycled = [];

        foreach ($this->availableConnections as $key => $connection) {
            if ($connection->getAge() > $this->config['max_lifetime']) {
                $recycled[] = $key;
                $this->destroyConnection($connection);
            }
        }

        // Remove recycled connections
        foreach (array_reverse($recycled) as $key) {
            unset($this->availableConnections[$key]);
        }

        // Re-index array
        $this->availableConnections = array_values($this->availableConnections);
    }

    /**
     * Ensure minimum number of connections are available
     *
     * @return void
     */
    private function ensureMinimumConnections(): void
    {
        while ($this->getTotalConnections() < $this->config['min_connections']) {
            try {
                $this->availableConnections[] = $this->createConnection();
            } catch (\Exception $e) {
                // Log failure but don't throw
                error_log('Failed to maintain minimum connections: ' . $e->getMessage());
                break;
            }
        }
    }

    /**
     * Health check all active connections
     *
     * @return void
     */
    private function healthCheckConnections(): void
    {
        $unhealthy = [];

        foreach ($this->activeConnections as $id => $connection) {
            if (!$this->isConnectionHealthy($connection)) {
                $unhealthy[] = $id;
            }
        }

        // Mark unhealthy connections for recycling when released
        foreach ($unhealthy as $id) {
            if (isset($this->activeConnections[$id])) {
                $this->activeConnections[$id]->markUnhealthy();
            }
        }
    }

    /**
     * Get pool statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_connections' => count($this->activeConnections),
            'idle_connections' => count($this->availableConnections),
            'total_connections' => $this->getTotalConnections(),
            'config' => $this->config
        ]);
    }

    /**
     * Update pool statistics during maintenance
     *
     * @return void
     */
    private function updatePoolStatistics(): void
    {
        // Update peak active connections if current exceeds previous peak
        $currentActive = count($this->activeConnections);
        if ($currentActive > $this->stats['peak_active']) {
            $this->stats['peak_active'] = $currentActive;
        }

        // Add additional runtime statistics
        $this->stats['current_available'] = count($this->availableConnections);
        $this->stats['current_active'] = $currentActive;
        $this->stats['current_total'] = $currentActive + count($this->availableConnections);

        // Calculate efficiency metrics
        if ($this->stats['total_acquisitions'] > 0) {
            $successful = $this->stats['total_acquisitions'] - $this->stats['total_timeouts'];
            $this->stats['success_rate'] = round(
                ($successful / $this->stats['total_acquisitions']) * 100,
                2
            );
        }

        if ($this->stats['total_health_checks'] > 0) {
            $successful_checks = $this->stats['total_health_checks'] - $this->stats['failed_health_checks'];
            $this->stats['health_success_rate'] = round(
                ($successful_checks / $this->stats['total_health_checks']) * 100,
                2
            );
        }

        // Connection lifecycle metrics
        if ($this->stats['total_created'] > 0) {
            $this->stats['average_lifetime'] = ($this->stats['total_destroyed'] > 0)
                ? round($this->stats['total_destroyed'] / $this->stats['total_created'], 2)
                : 0;
        }

        // Pool utilization metrics
        $maxConnections = $this->config['max_connections'];
        $this->stats['utilization_percent'] = round(($currentActive / $maxConnections) * 100, 2);

        // Maintenance tracking
        $this->stats['last_maintenance'] = microtime(true);
    }

    /**
     * Shutdown the pool and close all connections
     *
     * @return void
     */
    public function shutdown(): void
    {
        // Stop maintenance worker first
        $this->stopMaintenanceWorker();

        // Destroy all available connections
        foreach ($this->availableConnections as $connection) {
            $this->destroyConnection($connection);
        }

        // Clear arrays
        $this->availableConnections = [];
        $this->activeConnections = [];
    }

    /**
     * Get maintenance worker status
     *
     * @return array
     */
    public function getMaintenanceWorkerStatus(): array
    {
        return [
            'running' => $this->maintenanceWorkerRunning,
            'last_run' => $this->lastMaintenanceRun,
            'maintenance_runs' => $this->stats['maintenance_runs'],
            'maintenance_failures' => $this->stats['maintenance_failures'],
            'next_run_in' => $this->maintenanceWorkerRunning
                ? max(0, $this->config['health_check_interval'] - (microtime(true) - $this->lastMaintenanceRun))
                : null
        ];
    }
}
