<?php

namespace Glueful\Controllers;

use Glueful\Helpers\Request;
use Glueful\Http\Response;
use Glueful\Scheduler\JobScheduler;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
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

    // Whitelisted job names for security
    private const ALLOWED_JOB_NAMES = [
        'cache_maintenance',
        'database_backup',
        'log_cleaner',
        'notification_retry_processor',
        'session_cleaner',
        'archive_cleanup',
        'metrics_aggregation',
        'security_scan',
        'health_check'
    ];

    // Maximum allowed data size (in bytes)
    private const MAX_JOB_DATA_SIZE = 65536; // 64KB

    // Job name validation pattern
    private const JOB_NAME_PATTERN = '/^[a-z][a-z0-9_]*[a-z0-9]$/';

    public function __construct(
        ?JobScheduler $scheduler = null,
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?SymfonyRequest $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);

        // Initialize scheduler with dependency injection
        $this->scheduler = $scheduler ?? JobScheduler::getInstance();
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
        if (!preg_match(self::JOB_NAME_PATTERN, $jobName)) {
            throw new ValidationException(
                'Job name must start with a letter, contain only lowercase letters, numbers, ' .
                'and underscores, and end with a letter or number'
            );
        }

        // Whitelist validation
        if (!in_array($jobName, self::ALLOWED_JOB_NAMES, true)) {
            // Log security violation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'unauthorized_job_name_attempted',
                AuditEvent::SEVERITY_WARNING,
                [
                    'attempted_job_name' => $jobName,
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'ip_address' => $this->request->getClientIp(),
                    'allowed_jobs' => self::ALLOWED_JOB_NAMES,
                    'controller' => static::class
                ]
            );

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
        if (strlen($serialized) > self::MAX_JOB_DATA_SIZE) {
            throw new ValidationException(
                sprintf(
                    'Job data size (%d bytes) exceeds maximum allowed size (%d bytes)',
                    strlen($serialized),
                    self::MAX_JOB_DATA_SIZE
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
                $this->auditLogger->audit(
                    AuditEvent::CATEGORY_SYSTEM,
                    'dangerous_job_data_attempted',
                    AuditEvent::SEVERITY_CRITICAL,
                    [
                        'dangerous_pattern' => $pattern,
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'ip_address' => $this->request->getClientIp(),
                        'job_data_preview' => substr($serialized, 0, 200),
                        'controller' => static::class
                    ]
                );

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
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_execution_frequency_check',
            AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $userId,
                'execution_context' => $context,
                'timestamp' => time(),
                'controller' => static::class
            ]
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
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_list_accessed',
            AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'controller' => static::class,
                'action' => 'getScheduledJobs',
                'ip_address' => $this->request->getClientIp(),
                'user_agent' => $this->request->headers->get('User-Agent'),
                'timestamp' => time()
            ]
        );

        // Cache job list with permission-aware TTL
        $jobs = $this->cacheByPermission(
            'scheduled_jobs_list',
            function () {
                return $this->scheduler->getJobs();
            },
            300 // Default 5 minutes
        );

        $executionTime = (microtime(true) - $startTime) * 1000;

        // Log successful retrieval with metrics
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_list_retrieved',
            AuditEvent::SEVERITY_INFO,
            [
                'user_uuid' => $this->getCurrentUserUuid(),
                'job_count' => count($jobs),
                'execution_time_ms' => round($executionTime, 2),
                'cache_used' => true,
                'controller' => static::class
            ]
        );

        if (empty($jobs)) {
            return Response::ok([], 'No jobs found')->send();
        }

        return Response::ok($jobs, 'Jobs retrieved successfully')->send();
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
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_execution_started',
            AuditEvent::SEVERITY_INFO,
            [
                'operation_id' => $operationId,
                'execution_type' => 'due_jobs',
                'user_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp(),
                'user_agent' => $this->request->headers->get('User-Agent'),
                'controller' => static::class,
                'timestamp' => time()
            ]
        );

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
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'job_execution_failed',
                AuditEvent::SEVERITY_ERROR,
                [
                    'operation_id' => $operationId,
                    'execution_type' => 'due_jobs',
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'error_message' => $e->getMessage(),
                    'execution_time_ms' => round($executionTime, 2),
                    'controller' => static::class
                ]
            );

            throw $e; // Re-throw for global exception handler
        }

        // Log successful execution
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_execution_completed',
            AuditEvent::SEVERITY_INFO,
            [
                'operation_id' => $operationId,
                'execution_type' => 'due_jobs',
                'user_uuid' => $this->getCurrentUserUuid(),
                'execution_time_ms' => round($executionTime, 2),
                'success' => $success,
                'controller' => static::class
            ]
        );

        // Invalidate job cache after execution
        $this->invalidateCache(['scheduled_jobs', 'job_execution']);

        return Response::ok(null, 'Scheduled tasks completed')->send();
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
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'high_risk_job_execution_started',
            AuditEvent::SEVERITY_WARNING,
            [
                'operation_id' => $operationId,
                'execution_type' => 'all_jobs',
                'user_uuid' => $this->getCurrentUserUuid(),
                'is_admin' => $this->isAdmin(),
                'ip_address' => $this->request->getClientIp(),
                'user_agent' => $this->request->headers->get('User-Agent'),
                'controller' => static::class,
                'timestamp' => time()
            ]
        );

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
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'high_risk_job_execution_failed',
                AuditEvent::SEVERITY_CRITICAL,
                [
                    'operation_id' => $operationId,
                    'execution_type' => 'all_jobs',
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'error_message' => $e->getMessage(),
                    'execution_time_ms' => round($executionTime, 2),
                    'controller' => static::class
                ]
            );

            throw $e;
        }

        // Log successful high-risk operation
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'high_risk_job_execution_completed',
            AuditEvent::SEVERITY_WARNING,
            [
                'operation_id' => $operationId,
                'execution_type' => 'all_jobs',
                'user_uuid' => $this->getCurrentUserUuid(),
                'execution_time_ms' => round($executionTime, 2),
                'success' => $success,
                'controller' => static::class
            ]
        );

        // Invalidate all job-related caches
        $this->invalidateCache(['scheduled_jobs', 'job_execution', 'job_stats']);

        return Response::ok(null, 'All scheduled tasks completed')->send();
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
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'specific_job_execution_started',
            AuditEvent::SEVERITY_INFO,
            [
                'operation_id' => $operationId,
                'job_name' => $jobName,
                'execution_type' => 'specific_job',
                'user_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp(),
                'user_agent' => $this->request->headers->get('User-Agent'),
                'controller' => static::class,
                'timestamp' => time()
            ]
        );

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
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'specific_job_execution_failed',
                AuditEvent::SEVERITY_ERROR,
                [
                    'operation_id' => $operationId,
                    'job_name' => $jobName,
                    'execution_type' => 'specific_job',
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'error_message' => $e->getMessage(),
                    'execution_time_ms' => round($executionTime, 2),
                    'controller' => static::class
                ]
            );

            throw $e;
        }

        // Log successful specific job execution
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'specific_job_execution_completed',
            AuditEvent::SEVERITY_INFO,
            [
                'operation_id' => $operationId,
                'job_name' => $jobName,
                'execution_type' => 'specific_job',
                'user_uuid' => $this->getCurrentUserUuid(),
                'execution_time_ms' => round($executionTime, 2),
                'success' => $success,
                'controller' => static::class
            ]
        );

        // Invalidate job-specific cache
        $this->invalidateCache(['scheduled_jobs', 'job:' . $jobName]);

        return Response::ok(null, 'Scheduled task completed')->send();
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

        $data = Request::getPostData();

        if (!isset($data['job_name']) || !isset($data['job_data'])) {
            // Log validation failure
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'job_creation_validation_failed',
                AuditEvent::SEVERITY_WARNING,
                [
                    'operation_id' => $operationId,
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'validation_error' => 'Missing required fields: job_name or job_data',
                    'provided_fields' => array_keys($data),
                    'ip_address' => $this->request->getClientIp(),
                    'controller' => static::class
                ]
            );

            return Response::error('Job name and data are required', Response::HTTP_BAD_REQUEST)->send();
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
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_creation_started',
            AuditEvent::SEVERITY_INFO,
            [
                'operation_id' => $operationId,
                'job_name' => $data['job_name'],
                'job_data_type' => gettype($data['job_data']),
                'job_data_keys' => is_array($data['job_data']) ? array_keys($data['job_data']) : null,
                'user_uuid' => $this->getCurrentUserUuid(),
                'ip_address' => $this->request->getClientIp(),
                'user_agent' => $this->request->headers->get('User-Agent'),
                'controller' => static::class,
                'timestamp' => time()
            ]
        );

        try {
            $this->scheduler->register($data['job_name'], $sanitizedJobData);
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = true;
        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $success = false;

            // Log job creation failure
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'job_creation_failed',
                AuditEvent::SEVERITY_ERROR,
                [
                    'operation_id' => $operationId,
                    'job_name' => $data['job_name'],
                    'user_uuid' => $this->getCurrentUserUuid(),
                    'error_message' => $e->getMessage(),
                    'execution_time_ms' => round($executionTime, 2),
                    'controller' => static::class
                ]
            );

            throw $e;
        }

        // Log successful job creation
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'job_creation_completed',
            AuditEvent::SEVERITY_INFO,
            [
                'operation_id' => $operationId,
                'job_name' => $data['job_name'],
                'user_uuid' => $this->getCurrentUserUuid(),
                'execution_time_ms' => round($executionTime, 2),
                'success' => $success,
                'controller' => static::class
            ]
        );

        // Invalidate job list cache after creating new job
        $this->invalidateCache(['scheduled_jobs', 'job_count']);

        return Response::ok(null, 'Scheduled task created')->send();
    }
}
