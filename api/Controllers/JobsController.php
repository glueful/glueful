<?php

namespace Glueful\Controllers;

use Glueful\Helpers\RequestHelper;
use Glueful\Http\Response;
use Glueful\Scheduler\JobScheduler;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Exceptions\ValidationException;
use Glueful\Exceptions\SecurityException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Jobs Controller
 *
 * Handles scheduled job management operations:
 * - Listing jobs
 * - Running jobs (all, due, or specific)
 * - Creating new jobs
 *
 * @package Glueful\Controllers
 */
class JobsController extends BaseController
{
    private JobScheduler $scheduler;

    // Note: Job security settings are now in config/security.php

    public function __construct(
        ?JobScheduler $scheduler = null,
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?SymfonyRequest $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $request);

        // Initialize scheduler with dependency injection
        $this->scheduler = $scheduler ?? JobScheduler::getInstance();
    }

    /**
     * Get allowed job names from configuration
     *
     * @return array List of allowed job names
     */
    private function getAllowedJobNames(): array
    {
        $allowedNames = config('security.jobs.allowed_names', []);

        // Optionally auto-include scheduled jobs if enabled
        if (config('security.jobs.auto_allow_scheduled_jobs', false)) {
            $scheduleConfig = config('schedule.jobs', []);
            foreach ($scheduleConfig as $job) {
                if (isset($job['name'])) {
                    $allowedNames[] = $job['name'];
                }
            }
        }

        return array_unique($allowedNames);
    }

    /**
     * Validate job name according to security policies
     *
     * @param string $jobName Job name to validate
     * @throws ValidationException If job name is invalid
     * @throws SecurityException If job name is not whitelisted
     */
    private function validateJobName(string $jobName): void
    {
        // Basic validation
        if (empty($jobName)) {
            throw new ValidationException('Job name cannot be empty');
        }

        if (strlen($jobName) < 3 || strlen($jobName) > 64) {
            throw new ValidationException('Job name must be between 3 and 64 characters');
        }

        // Pattern validation
        $pattern = config('security.jobs.job_name_pattern', '/^[a-z][a-z0-9_]*[a-z0-9]$/');
        if (!preg_match($pattern, $jobName)) {
            throw new ValidationException(
                'Job name must start with a letter, contain only lowercase letters, numbers, ' .
                'and underscores, and end with a letter or number'
            );
        }

        // Whitelist validation
        $allowedNames = $this->getAllowedJobNames();
        if (!in_array($jobName, $allowedNames, true)) {
            // Log security violation

            throw new SecurityException(
                'Job name "' . $jobName . '" is not in the allowed list of job names'
            );
        }
    }

    /**
     * Validate job data structure and content
     *
     * @param mixed $jobData Job data to validate
     * @throws ValidationException If job data is invalid
     */
    private function validateJobData($jobData): void
    {
        // Check if data is serializable
        try {
            $serialized = serialize($jobData);
        } catch (\Exception $e) {
            throw new ValidationException('Job data must be serializable');
        }

        // Check data size
        $maxSize = config('security.jobs.max_job_data_size', 65536);
        if (strlen($serialized) > $maxSize) {
            throw new ValidationException(
                sprintf(
                    'Job data size (%d bytes) exceeds maximum allowed size (%d bytes)',
                    strlen($serialized),
                    $maxSize
                )
            );
        }

        // Validate array structure if applicable
        if (is_array($jobData)) {
            $this->validateJobDataArray($jobData);
        }
    }

    /**
     * Validate job data array structure
     *
     * @param array $jobData Job data array to validate
     * @throws ValidationException If array structure is invalid
     */
    private function validateJobDataArray(array $jobData): void
    {
        // Check nesting depth
        $maxDepth = 5;
        if ($this->getArrayDepth($jobData) > $maxDepth) {
            throw new ValidationException("Job data array nesting exceeds maximum depth of {$maxDepth}");
        }

        // Check for dangerous functions/classes
        $dangerousPatterns = [
            'eval',
            'exec',
            'system',
            'shell_exec',
            'passthru',
            'file_get_contents',
            'file_put_contents',
            'unlink',
            'chmod',
            'chown'
        ];

        $serialized = serialize($jobData);
        foreach ($dangerousPatterns as $pattern) {
            if (stripos($serialized, $pattern) !== false) {
                // Log security violation

                throw new SecurityException(
                    'Job data contains potentially dangerous content: ' . $pattern
                );
            }
        }
    }

    /**
     * Calculate array depth recursively
     *
     * @param array $array Array to analyze
     * @return int Maximum depth
     */
    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = 1 + $this->getArrayDepth($value);
                $maxDepth = max($maxDepth, $depth);
            }
        }
        return $maxDepth;
    }

    /**
     * Sanitize job data to remove potentially harmful content
     *
     * @param mixed $jobData Raw job data
     * @return mixed Sanitized job data
     */
    private function sanitizeJobData($jobData)
    {
        if (is_string($jobData)) {
            // Remove potential script tags and dangerous characters
            $jobData = strip_tags($jobData);
            $jobData = htmlspecialchars($jobData, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Remove null bytes and control characters
            $jobData = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $jobData);

            // Trim excessive whitespace
            $jobData = trim($jobData);
            $jobData = preg_replace('/\s+/', ' ', $jobData);
        } elseif (is_array($jobData)) {
            foreach ($jobData as $key => $value) {
                // Sanitize keys
                $cleanKey = is_string($key) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $key) : $key;

                // Recursively sanitize values
                $jobData[$cleanKey] = $this->sanitizeJobData($value);

                // Remove original key if it was changed
                if ($key !== $cleanKey) {
                    unset($jobData[$key]);
                }
            }
        } elseif (is_object($jobData)) {
            // Convert objects to arrays for safer handling
            $jobData = (array) $jobData;
            $jobData = $this->sanitizeJobData($jobData);
        }

        return $jobData;
    }

    /**
     * Check if operation requires enhanced security validation
     *
     * @param string $operation Operation type
     * @param array $context Additional context
     * @throws SecurityException If security requirements are not met
     */
    private function validateSecurityRequirements(string $operation, array $context = []): void
    {
        // Enhanced behavior check for sensitive operations
        $sensitiveOperations = ['run_all_jobs', 'create_job'];

        if (in_array($operation, $sensitiveOperations)) {
            // Require lower behavior score for sensitive operations
            $maxScore = match ($operation) {
                'run_all_jobs' => 0.3, // Very strict for running all jobs
                'create_job' => 0.6,   // Moderate for job creation
                default => 0.8
            };

            $this->requireLowRiskBehavior($maxScore, $operation);
        }

        // Additional checks for job execution operations
        if (str_starts_with($operation, 'run_')) {
            // Check if user has executed too many jobs recently
            $this->validateExecutionFrequency($context);
        }
    }

    /**
     * Validate job execution frequency to prevent abuse
     *
     * @param array $context Execution context
     * @throws SecurityException If execution frequency is suspicious
     */
    private function validateExecutionFrequency(array $context): void
    {
        // This would integrate with a more sophisticated tracking system
        // For now, we'll implement basic validation

        $userId = $this->getCurrentUserUuid();
        if (!$userId) {
            return; // Anonymous users already restricted by other means
        }

        // Log the execution for monitoring
    }

    /**
     * Get scheduled jobs data without sending response (for internal use)
     *
     * @return array Scheduled jobs data
     */
    public function getScheduledJobsData(): array
    {
        return $this->cacheResponse(
            'scheduled_jobs_list',
            function () {
                $rawJobs = $this->scheduler->getJobs();

                // Load configuration jobs data directly from config file
                $configFile = dirname(__DIR__, 2) . '/config/schedule.php';
                $configJobs = [];
                if (file_exists($configFile)) {
                    $scheduleConfig = require $configFile;
                    if (isset($scheduleConfig['jobs']) && is_array($scheduleConfig['jobs'])) {
                        foreach ($scheduleConfig['jobs'] as $configJob) {
                            if (isset($configJob['name'])) {
                                $configJobs[$configJob['name']] = $configJob;
                            }
                        }
                    }
                }

                // Transform job arrays into clean API response format
                $serializedJobs = [];
                foreach ($rawJobs as $job) {
                    $jobName = $job['name'] ?? 'Unknown';

                    // Check if this is a config job and merge data
                    if (isset($configJobs[$jobName])) {
                        $configData = $configJobs[$jobName];
                        $serializedJobs[] = [
                            'name' => $jobName,
                            'description' => $configData['description'] ?? '',
                            'schedule' => $configData['schedule'] ?? $job['schedule'] ?? '',
                            'handler_class' => $configData['handler_class'] ?? '',
                            'enabled' => $configData['enabled'] ?? true,
                            'persistence' => $configData['persistence'] ?? false,
                            'timeout' => $configData['timeout'] ?? 300,
                            'retry_attempts' => $configData['retry_attempts'] ?? 1,
                            'parameters' => $configData['parameters'] ?? [],
                            'next_run' => $job['next_run'] ?? null,
                            'last_run' => $job['last_run'] ?? null,
                            'status' => isset($configData['enabled'])
                                ? ($configData['enabled'] ? 'active' : 'inactive')
                                : 'active'
                        ];
                    } else {
                        // For database jobs or other jobs, use the existing structure
                        $serializedJobs[] = [
                            'name' => $jobName,
                            'description' => $job['description'] ?? '',
                            'schedule' => $job['schedule'] ?? '',
                            'handler_class' => $job['handler_class'] ?? '',
                            'enabled' => $job['enabled'] ?? true,
                            'persistence' => $job['persistence'] ?? false,
                            'timeout' => $job['timeout'] ?? 300,
                            'retry_attempts' => $job['retry_attempts'] ?? 1,
                            'parameters' => is_array($job['parameters'] ?? null) ? $job['parameters'] : [],
                            'next_run' => $job['next_run'] ?? null,
                            'last_run' => $job['last_run'] ?? null,
                            'status' => $job['status'] ?? ($job['enabled'] ? 'active' : 'inactive')
                        ];
                    }
                }

                return $serializedJobs;
            },
            300 // Default 5 minutes
        );
    }

    /**
     * Get all jobs with pagination and filtering
     *
     * @return mixed HTTP response
     */
    public function getScheduledJobs(): mixed
    {
        $startTime = microtime(true);

        // Check permission to view jobs
        $this->requirePermission('system.jobs.view');

        // Log job list access

        // Use the new data method to get scheduled jobs
        $jobs = $this->getScheduledJobsData();

        $executionTime = (microtime(true) - $startTime) * 1000;

        // Log successful retrieval with metrics

        if (empty($jobs)) {
            return Response::success([], 'No jobs found');
        }

        return Response::success($jobs, 'Jobs retrieved successfully');
    }

    /**
     * Run all due jobs
     *
     * @return mixed HTTP response
     */
    public function runDueJobs(): mixed
    {
        $startTime = microtime(true);
        $operationId = uniqid('job_exec_', true);

        // Check permission to execute jobs
        // Allow both general execute permission or specific due jobs permission
        if (!$this->canAny(['system.jobs.execute', 'system.jobs.execute.due'])) {
            $this->requirePermission('system.jobs.execute');
        }

        // Log job execution start

        // Apply rate limiting: 10 attempts per 5 minutes
        $this->rateLimit('run_due_jobs', 10, 300);

        // Enhanced security validation for job execution
        $this->validateSecurityRequirements('run_due_jobs', ['execution_type' => 'due_jobs']);

        try {
            $this->scheduler->runDueJobs();
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = true;
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = false;

            // Log execution failure

            throw $e; // Re-throw for global exception handler
        }

        // Log successful execution

        // Invalidate job cache after execution
        $this->invalidateCache(['scheduled_jobs', 'job_execution']);

        return Response::success(null, 'Scheduled tasks completed');
    }

    /**
     * Run all jobs regardless of schedule
     *
     * @return mixed HTTP response
     */
    public function runAllJobs(): mixed
    {
        $startTime = microtime(true);
        $operationId = uniqid('job_exec_all_', true);

        // Running all jobs is a high-risk operation - require admin or specific permission
        if (!$this->isAdmin()) {
            // Non-admin users need both execute permission and specific all permission
            $this->requirePermission('system.jobs.execute');
            $this->requirePermission('system.jobs.execute.all');
        }

        // Log high-risk operation start

        // Apply strict rate limiting for this high-risk operation: 3 attempts per 10 minutes
        $this->rateLimit('run_all_jobs', 3, 600);

        // Additional behavior check for high-risk operations
        $this->requireLowRiskBehavior(0.5, 'run_all_jobs');

        // Enhanced security validation for high-risk operation
        $this->validateSecurityRequirements('run_all_jobs', [
            'execution_type' => 'all_jobs',
            'is_admin' => $this->isAdmin()
        ]);

        try {
            $this->scheduler->runAllJobs();
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = true;
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = false;

            // Log critical failure

            throw $e;
        }

        // Log successful high-risk operation

        // Invalidate all job-related caches
        $this->invalidateCache(['scheduled_jobs', 'job_execution', 'job_stats']);

        return Response::success(null, 'All scheduled tasks completed');
    }

    /**
     * Run a specific job
     *
     * @param string $jobName Name of the job to run
     * @return mixed HTTP response
     */
    public function runJob($jobName): mixed
    {

        $startTime = microtime(true);
        $operationId = uniqid('job_exec_specific_', true);

        // Validate job name for security
        $this->validateJobName($jobName);

        // Check permission to execute jobs
        // Allow general execute permission or specific job execution permission
        if (!$this->canAny(['system.jobs.execute', 'system.jobs.execute.specific'])) {
            $this->requirePermission('system.jobs.execute');
        }

        // Enhanced security validation for job execution
        $this->validateSecurityRequirements('run_specific_job', ['job_name' => $jobName]);

        // Log specific job execution start

        // Apply rate limiting: 20 attempts per 5 minutes
        $this->rateLimit('run_specific_job', 20, 300);

        try {
            $this->scheduler->runJob($jobName);
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = true;
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = false;

            // Log specific job failure

            throw $e;
        }

        // Log successful specific job execution

        // Invalidate job-specific cache
        $this->invalidateCache(['scheduled_jobs', 'job:' . $jobName]);

        return Response::success(null, 'Scheduled task completed');
    }

    /**
     * Create a new scheduled job
     *
     * @return mixed HTTP response
     */
    public function createJob(): mixed
    {
        $startTime = microtime(true);
        $operationId = uniqid('job_create_', true);

        // Check permission to create jobs
        $this->requirePermission('system.jobs.create');

        // Apply rate limiting: 30 attempts per hour
        $this->rateLimit('create_job', 30, 3600);

        $data = RequestHelper::getRequestData();

        if (!isset($data['job_name']) || !isset($data['job_data'])) {
            // Log validation failure

            return Response::error('Job name and data are required', Response::HTTP_BAD_REQUEST);
        }

        // Validate job name for security
        $this->validateJobName($data['job_name']);

        // Validate job data structure and content
        $this->validateJobData($data['job_data']);

        // Enhanced security validation for job creation
        $this->validateSecurityRequirements('create_job', [
            'job_name' => $data['job_name'],
            'job_data_size' => strlen(serialize($data['job_data']))
        ]);

        // Sanitize job data before storage
        $sanitizedJobData = $this->sanitizeJobData($data['job_data']);

        // Log job creation attempt with sanitized parameters

        try {
            $this->scheduler->register($data['job_name'], $sanitizedJobData);
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = true;
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = false;

            // Log job creation failure

            throw $e;
        }

        // Invalidate job list cache after creating new job
        $this->invalidateCache(['scheduled_jobs', 'job_count']);

        return Response::success(null, 'Scheduled task created');
    }
}
