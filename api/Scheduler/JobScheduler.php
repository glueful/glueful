<?php

namespace Glueful\Scheduler;

use Cron\CronExpression;
use DateTime;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Lock\LockManagerInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;

/**
 * Job Scheduler
 *
 * Manages scheduled tasks and their execution based on cron expressions.
 * Provides a flexible system for scheduling and running periodic tasks
 * with proper error handling and logging.
 *
 * Features:
 * - Cron expression based scheduling
 * - Named jobs for tracking
 * - Error handling and logging
 * - Manual and automatic execution modes
 * - Database persistence for jobs
 *
 * Example Usage:
 * ```php
 * $scheduler = new JobScheduler();
 *
 * // Schedule a daily backup
 * $scheduler->register('@daily', function() {
 *     // Backup logic here
 * }, 'daily-backup');
 *
 * // Schedule hourly cleanup
 * $scheduler->register('0 * * * *', function() {
 *     // Cleanup logic here
 * }, 'hourly-cleanup');
 *
 * // Run due jobs
 * $scheduler->runDueJobs();
 * ```
 *
 * Cron Expression Format:
 * ```
 * * * * * *
 * │ │ │ │ │
 * │ │ │ │ └── Day of Week   (0-6) (Sunday=0)
 * │ │ │ └──── Month         (1-12)
 * │ │ └────── Day of Month  (1-31)
 * │ └──────── Hour          (0-23)
 * └────────── Minute        (0-59)
 * ```
 *
 * Special expressions:
 * - @yearly   - Once a year (0 0 1 1 *)
 * - @monthly  - Once a month (0 0 1 * *)
 * - @weekly   - Once a week (0 0 * * 0)
 * - @daily    - Once a day (0 0 * * *)
 * - @hourly   - Once an hour (0 * * * *)
 *
 * @package Glueful\Scheduler
 */
class JobScheduler
{
    /** @var array List of registered jobs and their schedules */
    protected array $jobs = [];

    /** @var Connection Database connection */
    protected Connection $db;

    /** @var LockManagerInterface Lock manager for preventing concurrent executions */
    protected LockManagerInterface $lockManager;

    /**
     * Constructor
     */
    public function __construct(?LockManagerInterface $lockManager = null)
    {
        $this->db = new Connection();

        // Get lock manager from container if not provided
        if ($lockManager) {
            $this->lockManager = $lockManager;
        } else {
            global $container;
            $this->lockManager = $container?->get(LockManagerInterface::class) ?? null;
        }

        // Ensure required database tables exist before trying to use them
        $this->ensureTablesExist();

        $this->loadJobsFromDatabase();

         // Register core jobs from config file
         $this->loadCoreJobsFromConfig();
    }

    /**
     * Ensure scheduler database tables exist
     *
     * Creates required tables for job scheduling if they don't exist yet:
     * - scheduled_jobs: Stores job definitions and schedules
     * - job_executions: Tracks job execution history
     */
    protected function ensureTablesExist(): void
    {
        try {
            $connection = new Connection();
            $schema = $connection->getSchemaBuilder();

            // Create Scheduled Jobs Table
            if (!$schema->hasTable('scheduled_jobs')) {
                $table = $schema->table('scheduled_jobs');

                // Define columns
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('name', 255);
                $table->string('schedule', 100);
                $table->string('handler_class', 255);
                $table->json('parameters')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->dateTime('last_run')->nullable();
                $table->dateTime('next_run')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->dateTime('updated_at')->nullable();

                // Add indexes
                $table->index('name');
                $table->index('next_run');
                $table->index('is_enabled');

                // Create the table
                $table->create();
            }

            // Create Job Executions Table
            if (!$schema->hasTable('job_executions')) {
                $table = $schema->table('job_executions');

                // Define columns
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('job_uuid', 12);
                $table->string('status', 20); // Will use CHECK constraint in some databases
                $table->dateTime('started_at');
                $table->dateTime('completed_at')->nullable();
                $table->text('result')->nullable();
                $table->text('error_message')->nullable();
                $table->decimal('execution_time', 10, 2)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                // Add indexes
                $table->index('job_uuid');
                $table->index('status');
                $table->index('started_at');

                // Add foreign key
                $table->foreign('job_uuid')
                    ->references('uuid')
                    ->on('scheduled_jobs')
                    ->cascadeOnDelete();

                // Create the table
                $table->create();
            }

            // Execute all pending operations
            $schema->execute();
        } catch (\Exception $e) {
            $this->log("Failed to ensure table existence: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Register a new job with a schedule.
     *
     * @param string   $schedule  Cron expression (e.g., '* * * * *', '@daily')
     * @param callable $callback  Function to execute
     * @param string   $name      Job name
     */
    public function register(string $schedule, callable $callback, string $name = ''): void
    {
        $jobName = $name ?: 'job_' . count($this->jobs);

        $this->jobs[] = [
            'name' => $jobName,
            'schedule' => $schedule,
            'callback' => $callback,
        ];
    }

    /**
     * Register a job in the database for persistence
     *
     * @param string $name Job name
     * @param string $schedule Cron expression for job scheduling
     * @param string $handlerClass Class that will handle job execution
     * @param array $parameters Optional parameters for the job
     * @return string UUID of created job
     */
    public function registerInDatabase(
        string $name,
        string $schedule,
        string $handlerClass,
        array $parameters = []
    ): string {
        // Generate UUID for job
        $uuid = Utils::generateNanoID();

        // Calculate next run time
        $cronExpression = new CronExpression($schedule);
        $nextRunTime = $cronExpression->getNextRunDate()->format('Y-m-d H:i:s');

        // Insert job into database
        $this->db->table('scheduled_jobs')->insert([
            'uuid' => $uuid,
            'name' => $name,
            'schedule' => $schedule,
            'handler_class' => $handlerClass,
            'parameters' => json_encode($parameters),
            'is_enabled' => 1,
            'next_run' => $nextRunTime,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Also register in memory for current process
        $this->jobs[] = [
            'uuid' => $uuid,
            'name' => $name,
            'schedule' => $schedule,
            'handler_class' => $handlerClass,
            'parameters' => $parameters,
            'next_run' => $nextRunTime
        ];

        return $uuid;
    }

    /**
     * Load jobs from database into memory
     */
    protected function loadJobsFromDatabase(): void
    {
        try {
            $dbJobs = $this->db->table('scheduled_jobs')
                ->select(['*'])
                ->where('is_enabled', 1)
                ->orderBy('name', 'ASC')
                ->get();

            foreach ($dbJobs as $job) {
                // Create a callback for database jobs that uses the handler_class
                $callback = function () use ($job) {
                    $handlerClass = $job['handler_class'];
                    $parameters = json_decode($job['parameters'] ?? '{}', true);

                    if (class_exists($handlerClass) && method_exists($handlerClass, 'handle')) {
                        $handler = new $handlerClass();
                        return $handler->handle($parameters);
                    }

                    // Log error if handler doesn't exist
                    error_log("Job handler not found: {$job['handler_class']}");
                    return false;
                };

                // Register job in memory
                $this->jobs[] = [
                    'uuid' => $job['uuid'],
                    'name' => $job['name'],
                    'schedule' => $job['schedule'],
                    'callback' => $callback,
                    'next_run' => $job['next_run'],
                    'from_database' => true
                ];
            }
        } catch (\Exception $e) {
            error_log("Failed to load jobs from database: " . $e->getMessage());
        }
    }

    /**
     * Update job in database after execution
     *
     * @param string $jobUuid Job UUID
     * @param bool $success Whether execution succeeded
     * @param mixed $result Result data from execution
     */
    protected function recordJobExecution(string $jobUuid, bool $success, $result = null): void
    {
        try {
            $now = date('Y-m-d H:i:s');

            // Insert execution record
            $executionId = Utils::generateNanoID();
            $this->db->table('job_executions')->insert([
                'uuid' => $executionId,
                'job_uuid' => $jobUuid,
                'status' => $success ? 'success' : 'failure',
                'started_at' => $now,
                'completed_at' => $now,
                'result' => is_string($result) ? $result : json_encode($result),
                'created_at' => $now
            ]);

            // Update job's last_run and next_run
            $jobs = $this->db->table('scheduled_jobs')
                ->select(['schedule'])
                ->where('uuid', $jobUuid)
                ->limit(1)
                ->get();

            if (!empty($jobs)) {
                $job = $jobs[0];
                $cronExpression = new CronExpression($job['schedule']);
                $nextRunTime = $cronExpression->getNextRunDate()->format('Y-m-d H:i:s');

                $this->db->table('scheduled_jobs')
                    ->where('uuid', $jobUuid)
                    ->update([
                        'last_run' => $now,
                        'next_run' => $nextRunTime,
                        'updated_at' => $now
                    ]);
            }
        } catch (\Exception $e) {
            error_log("Failed to record job execution: " . $e->getMessage());
        }
    }

    /**
     * Run all jobs that are due at the current time.
     */
    public function runDueJobs(): void
    {
        $now = new DateTime();

        foreach ($this->jobs as $job) {
            if ((new CronExpression($job['schedule']))->isDue($now)) {
                $this->runJobWithLock($job);
            }
        }
    }

    /**
     * Run a job with distributed locking to prevent concurrent executions
     *
     * @param array $job Job configuration array
     * @return mixed Job execution result or null if lock cannot be acquired
     */
    protected function runJobWithLock(array $job): mixed
    {
        $jobName = $job['name'] ?? 'unknown';
        $lockResource = "scheduler:job:{$jobName}";
        $lockTtl = 3600.0; // 1 hour max execution time

        // Skip if no lock manager available
        if (!$this->lockManager) {
            $this->log("No lock manager available, running job '{$jobName}' without locking", 'warning');
            return $this->executeJob($job);
        }

        try {
            return $this->lockManager->executeWithLock($lockResource, function () use ($job, $jobName) {
                $this->log("🔒 Acquired lock for scheduled job: {$jobName}");
                return $this->executeJob($job);
            }, $lockTtl);
        } catch (LockConflictedException $e) {
            $this->log("⏳ Job '{$jobName}' is already running on another process - skipping", 'info');
            return null;
        } catch (\Throwable $e) {
            $this->log("❌ Lock error for job '{$jobName}': " . $e->getMessage(), 'error');

            // Record the error if job has a UUID
            if (isset($job['uuid'])) {
                $this->recordJobExecution($job['uuid'], false, "Lock error: " . $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Execute a job without locking (internal method)
     *
     * @param array $job Job configuration array
     * @return mixed Job execution result
     */
    protected function executeJob(array $job): mixed
    {
        $jobName = $job['name'] ?? 'unknown';

        try {
            $startTime = microtime(true);
            $result = call_user_func($job['callback']);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->log("✅ Executed job: {$jobName} (took {$executionTime}ms)");

            // Record successful execution in database if job has an ID
            if (isset($job['uuid'])) {
                $this->recordJobExecution($job['uuid'], true, $result);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->log("❌ Error in job '{$jobName}': " . $e->getMessage(), 'error');

            // Record execution error in database if job has an ID
            if (isset($job['uuid'])) {
                $this->recordJobExecution($job['uuid'], false, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Run all registered jobs manually (ignoring schedule).
     */
    public function runAllJobs(): void
    {
        foreach ($this->jobs as $job) {
            try {
                $result = call_user_func($job['callback']);
                $this->log("Executed job: {$job['name']}");

                // Record execution in database if job has an ID
                if (isset($job['uuid'])) {
                    $this->recordJobExecution($job['uuid'], true, $result);
                }
            } catch (\Throwable $e) {
                $this->log("Error in job '{$job['name']}': " . $e->getMessage(), 'error');

                // Record execution error in database
                if (isset($job['uuid'])) {
                    $this->recordJobExecution($job['uuid'], false, $e->getMessage());
                }
            }
        }
    }

    /**
     * Run a single job by name or UUID
     *
     * @param string $identifier Job name or UUID
     * @return mixed|null Result of job execution or null if job not found
     */
    public function runJob(string $identifier): mixed
    {
        // Find the job by name or UUID
        foreach ($this->jobs as $job) {
            if ($job['name'] === $identifier || ($job['uuid'] ?? '') === $identifier) {
                try {
                    $result = call_user_func($job['callback']);

                    // Record execution in database if job has a UUID
                    if (isset($job['uuid'])) {
                        $this->recordJobExecution($job['uuid'], true, $result);
                    }

                    return $result;
                } catch (\Throwable $e) {
                    $this->log("Error in job '{$job['name']}': " . $e->getMessage(), 'error');

                    // Record execution error in database if job has a UUID
                    if (isset($job['uuid'])) {
                        $this->recordJobExecution($job['uuid'], false, $e->getMessage());
                    }

                    throw $e; // Re-throw for higher-level handling
                }
            }
        }

        return null; // Job not found
    }

    public function loadCoreJobsFromConfig(): void
    {
        $configFile = dirname(__DIR__, 2) . '/config/schedule.php';
        if (!file_exists($configFile)) {
            return;
        }

        try {
            $coreJobs = require $configFile;
            if (!isset($coreJobs['jobs']) || !is_array($coreJobs['jobs'])) {
                $this->log('Invalid schedule configuration format', 'warning');
                return;
            }
            // error_log('Core jobs: ' . json_encode($coreJobs['jobs']));
            foreach ($coreJobs['jobs'] as $job) {
                // Skip disabled jobs

                // if (isset($job['enabled']) && !$job['enabled']) {
                //     continue;
                // }

                // Skip jobs with missing required fields
                if (!isset($job['name']) || !isset($job['schedule']) || !isset($job['handler_class'])) {
                    $this->log('Skipping job with missing required fields: ' . ($job['name'] ?? 'unnamed'), 'warning');
                    continue;
                }

                // Validate handler class existence
                if (!class_exists($job['handler_class'])) {
                    $this->log("Skipping job '{$job['name']}': Handler class not found", 'warning');
                    continue;
                }

                // Register based on persistence flag
                $isPersistent = $job['persistence'] ?? false;
                if ($isPersistent) {
                    $this->registerInDatabase(
                        $job['name'],
                        $job['schedule'],
                        $job['handler_class'],
                        $job['parameters'] ?? []
                    );
                    // $this->log("Registered persistent job: {$job['name']}", 'info');
                } else {
                    // $this->register($job['schedule'], function() use ($job) {
                    //     $handler = new $job['handler_class']();
                    //     return method_exists($handler, 'handle') ?
                    //         $handler->handle($job['parameters'] ?? []) :
                    //         false;
                    // }, $job['name']);
                    $this->register($job['schedule'], function () use ($job) {
                        return [
                            'handler_class' => $job['handler_class'],
                            'parameters' => $job['parameters'] ?? [],
                        ];
                    }, $job['name']);
                    // $this->log("Registered in-memory job: {$job['name']}", 'info');
                }
            }
            // error_log('Core jobs loaded successfully:'.json_encode($coreJobs['jobs']));
        } catch (\Exception $e) {
            $this->log('Failed to load jobs from config: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Get all registered jobs.
     *
     * @return array List of jobs
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Get all due jobs from database
     *
     * @return array List of jobs that should be executed now
     */
    public function getDueJobs(): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->table('scheduled_jobs')
            ->select(['*'])
            ->where('is_enabled', 1)
            ->where('next_run', '<=', $now)
            ->get();
    }

    /**
     * Log job execution results.
     *
     * @param string $message Log message
     * @param string $level   Log level (info, error)
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        echo "[$timestamp] [$level] $message" . PHP_EOL;
    }

    public static function getInstance(): JobScheduler
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new JobScheduler();
        }
        return $instance;
    }
}
