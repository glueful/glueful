<?php

namespace Glueful\Scheduler;

use Cron\CronExpression;
use DateTime;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Helpers\Utils;

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

    /** @var QueryBuilder Database query builder */
    protected QueryBuilder $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());


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
            $schema = $connection->getSchemaManager();

            // Create Scheduled Jobs Table
            $schema->createTable('scheduled_jobs', [
                'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
                'uuid' => 'CHAR(12) NOT NULL',
                'name' => 'VARCHAR(255) NOT NULL',
                'schedule' => 'VARCHAR(100) NOT NULL',
                'handler_class' => 'VARCHAR(255) NOT NULL',
                'parameters' => 'JSON',
                'is_enabled' => 'TINYINT(1) DEFAULT 1',
                'last_run' => 'DATETIME NULL',
                'next_run' => 'DATETIME NULL',
                'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'DATETIME NULL'
            ])->addIndex([
                ['type' => 'INDEX', 'column' => 'name', 'table' => 'scheduled_jobs'],
                ['type' => 'INDEX', 'column' => 'next_run', 'table' => 'scheduled_jobs'],
                ['type' => 'INDEX', 'column' => 'is_enabled', 'table' => 'scheduled_jobs']
            ]);

            // Create Job Executions Table
            $schema->createTable('job_executions', [
                'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
                'uuid' => 'CHAR(12) NOT NULL',
                'job_uuid' => 'CHAR(12) NOT NULL',
                'status' => "ENUM('success', 'failure', 'running') NOT NULL",
                'started_at' => 'DATETIME NOT NULL',
                'completed_at' => 'DATETIME NULL',
                'result' => 'TEXT NULL',
                'error_message' => 'TEXT NULL',
                'execution_time' => 'FLOAT NULL',
                'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ])->addIndex([
                ['type' => 'INDEX', 'column' => 'job_uuid', 'table' => 'job_executions'],
                ['type' => 'INDEX', 'column' => 'status', 'table' => 'job_executions'],
                ['type' => 'INDEX', 'column' => 'started_at', 'table' => 'job_executions'],
                ['type' => 'FOREIGN KEY', 
                    'column' => 'job_uuid', 
                    'table' => 'job_executions', 
                    'references' => 'uuid', 
                    'on' => 'scheduled_jobs', 
                    'onDelete' => 'CASCADE'
                ]
            ]);
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
        $this->db->insert('scheduled_jobs', [
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
            $dbJobs = $this->db->select('scheduled_jobs', ['*'])
                ->where(['is_enabled' => 1])
                ->orderBy(['name' => 'ASC'])
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
     * @param string $jobId Job UUID
     * @param bool $success Whether execution succeeded
     * @param mixed $result Result data from execution
     */
    protected function recordJobExecution(string $jobUud, bool $success, $result = null): void
    {
        try {
            $now = date('Y-m-d H:i:s');

            // Insert execution record
            $executionId = Utils::generateNanoID();
            $this->db->insert('job_executions', [
                'uuid' => $executionId,
                'job_uuid' => $jobUud,
                'status' => $success ? 'success' : 'failure',
                'started_at' => $now,
                'completed_at' => $now,
                'result' => is_string($result) ? $result : json_encode($result),
                'created_at' => $now
            ]);

            // Update job's last_run and next_run
            $job = $this->db->select('scheduled_jobs', ['schedule'])
                ->where(['uuid' => $jobUud])
                ->limit(1)
                ->get();

            if ($job) {
                $cronExpression = new CronExpression($job['schedule']);
                $nextRunTime = $cronExpression->getNextRunDate()->format('Y-m-d H:i:s');

                $this->db->upsert('scheduled_jobs', [
                    'last_run' => $now,
                    'next_run' => $nextRunTime,
                    'updated_at' => $now
                ], ['uuid' => $jobUud]);
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
                try {
                    $result = call_user_func($job['callback']);
                    $this->log("Executed job: {$job['name']}");

                    // Record execution in database if job has an ID (came from database)
                    if (isset($job['uuid'])) {
                        $this->recordJobExecution($job['uuid'], true, $result);
                    }
                } catch (\Throwable $e) {
                    $this->log("Error in job '{$job['name']}': " . $e->getMessage(), 'error');

                    // Record execution error in database if job has an ID
                    if (isset($job['uuid'])) {
                        $this->recordJobExecution($job['uuid'], false, $e->getMessage());
                    }
                }
            }
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

        return $this->db->select('scheduled_jobs', ['*'])
            ->where([
                'is_enabled' => 1,
                'next_run <= ?' => $now
            ])
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
