# Glueful Queue System

This comprehensive guide covers Glueful's enterprise-grade queue system, featuring multiple drivers, auto-scaling, batch processing, and advanced monitoring capabilities for high-performance background job processing.

## Table of Contents

1. [Overview](#overview)
2. [Queue Drivers](#queue-drivers)
3. [Queue Manager](#queue-manager)
4. [Job System](#job-system)
5. [Process Management](#process-management)
6. [Auto-Scaling](#auto-scaling)
7. [Batch Processing](#batch-processing)
8. [Failed Job Handling](#failed-job-handling)
9. [Monitoring and Statistics](#monitoring-and-statistics)
10. [CLI Commands](#cli-commands)
11. [Configuration](#configuration)
12. [Production Deployment](#production-deployment)

## Overview

Glueful's queue system provides a powerful, scalable solution for background job processing with support for multiple storage backends, automatic scaling, and comprehensive monitoring.

### Key Features

- **Multiple Drivers**: Database and Redis queue drivers with atomic operations
- **Auto-Scaling**: Intelligent worker scaling based on queue load and metrics
- **Process Management**: Advanced worker process management with health monitoring
- **Batch Processing**: Efficient bulk job processing with atomic operations
- **Priority Queues**: Priority-based job ordering for critical tasks
- **Delayed Jobs**: Schedule jobs for future execution
- **Failed Job Recovery**: Comprehensive failed job handling and retry mechanisms
- **Real-time Monitoring**: Queue statistics, worker metrics, and health monitoring
- **Plugin System**: Extensible architecture with driver discovery and validation

### Architecture Components

1. **QueueManager**: Central management for connections and operations
2. **Queue Drivers**: Database and Redis implementations
3. **ProcessManager**: Worker process lifecycle management
4. **AutoScaler**: Intelligent scaling based on load metrics
5. **Job Classes**: Base job system with serialization and error handling
6. **Monitoring System**: Real-time metrics and health monitoring

## Queue Drivers

### Database Queue Driver

The database driver provides ACID-compliant job storage with transaction support and efficient indexing.

#### Features

- **ACID Compliance**: Full transaction support for job operations
- **Atomic Job Reservation**: Row-level locking for job picking
- **Priority Queues**: Priority-based job ordering with compound indexes
- **Delayed Jobs**: Timestamp-based job scheduling
- **Failed Job Isolation**: Separate table for failed job tracking
- **Batch Operations**: Efficient bulk insertions

#### Usage

```php
use Glueful\Queue\QueueManager;

// Initialize queue manager with database driver
$queueManager = new QueueManager([
    'default' => 'database',
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'queue_jobs',
            'failed_table' => 'queue_failed_jobs',
            'retry_after' => 90
        ]
    ]
]);

// Push immediate job
$jobUuid = $queueManager->push('ProcessEmail', [
    'to' => 'user@example.com',
    'template' => 'welcome'
]);

// Push delayed job (5 minutes)
$delayedUuid = $queueManager->later(300, 'SendReminder', [
    'user_id' => 123,
    'type' => 'subscription_expiry'
]);

// Push high priority job
$priorityUuid = $queueManager->push('ProcessPayment', [
    'order_id' => 456,
    'amount' => 99.99,
    'priority' => 10
]);
```

#### Database Schema

```sql
-- Queue jobs table
CREATE TABLE queue_jobs (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(21) NOT NULL UNIQUE,
    queue VARCHAR(100) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts INTEGER DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    priority INTEGER DEFAULT 0,
    batch_uuid CHAR(21) NULL,
    
    INDEX idx_queue_processing (queue, reserved_at, available_at, priority),
    INDEX idx_batch_uuid (batch_uuid)
);

-- Failed jobs table
CREATE TABLE queue_failed_jobs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(12) NOT NULL UNIQUE,
    connection VARCHAR(255) NOT NULL,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    batch_uuid CHAR(12) NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_connection_queue (connection, queue),
    INDEX idx_failed_at (failed_at)
);
```

### Redis Queue Driver

The Redis driver provides high-performance job processing with atomic operations and memory efficiency.

#### Features

- **High Performance**: Memory-based storage with atomic operations
- **Atomic Operations**: Redis transactions for job consistency
- **Priority Queues**: Sorted sets for priority-based processing
- **Delayed Jobs**: Redis ZADD for timestamp-based scheduling
- **Memory Efficient**: Optimized data structures and job expiration
- **Connection Pooling**: Persistent connections with failover support

#### Redis Data Structures

```redis
# Queue structures
queue:{name}               # List for immediate jobs
queue:{name}:delayed       # Sorted set for delayed jobs (score = timestamp)
queue:{name}:reserved      # Sorted set for reserved jobs (score = timeout)
queue:{name}:failed        # List for failed jobs

# Job data
job:{uuid}                 # Hash containing job data

# Queue registry
queues                     # Set of all queue names
```

#### Usage

```php
// Redis queue configuration
$queueManager = new QueueManager([
    'default' => 'redis',
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'glueful:queue:',
            'retry_after' => 90,
            'job_expiration' => 3600
        ]
    ]
]);

// Redis supports all the same operations as database driver
$queueManager->push('ProcessImages', ['images' => [1, 2, 3]]);
$queueManager->later(600, 'CleanupTemp', ['older_than' => '1 hour']);
```

## Queue Manager

### Central Queue Management

The QueueManager provides a unified interface for all queue operations across multiple drivers and connections.

```php
use Glueful\Queue\QueueManager;

// Create from configuration
$queueManager = QueueManager::createDefault();

// Or from custom config file
$queueManager = QueueManager::fromConfigFile('/path/to/queue.php');

// Get specific connection
$redisQueue = $queueManager->connection('redis');
$dbQueue = $queueManager->connection('database');

// Push to specific connection and queue
$uuid = $queueManager->push(
    'ProcessOrder', 
    ['order_id' => 123], 
    'orders',        // queue name
    'redis'          // connection name
);
```

### Bulk Operations

```php
// Bulk job creation for high throughput
$jobs = [
    ['job' => 'ProcessEmail', 'data' => ['to' => 'user1@example.com']],
    ['job' => 'ProcessEmail', 'data' => ['to' => 'user2@example.com']],
    ['job' => 'ProcessEmail', 'data' => ['to' => 'user3@example.com'], 'delay' => 300],
    ['job' => 'ProcessSMS', 'data' => ['to' => '+1234567890'], 'priority' => 5]
];

$uuids = $queueManager->bulk($jobs, 'notifications');
// Returns: ['uuid1', 'uuid2', 'uuid3', 'uuid4']
```

### Queue Statistics and Health

```php
// Get queue statistics
$stats = $queueManager->getStats('orders');
/*
[
    'total' => 150,
    'pending' => 120,
    'reserved' => 25,
    'delayed' => 5,
    'failed' => 3,
    'queues' => ['orders', 'emails', 'images']
]
*/

// Test connection health
$health = $queueManager->testConnection('redis');
/*
[
    'connection' => 'redis',
    'healthy' => true,
    'message' => 'Redis connection is healthy',
    'metrics' => [
        'redis_version' => '6.2.6',
        'total_queues' => 5,
        'total_jobs' => 1250
    ],
    'response_time' => 2.5
]
*/

// Get available drivers and their capabilities
$drivers = $queueManager->getAvailableDrivers();
/*
[
    [
        'name' => 'database',
        'version' => '1.0.0',
        'description' => 'Database-backed queue driver with transaction support',
        'features' => ['delayed_jobs', 'priority_queues', 'atomic_operations'],
        'dependencies' => []
    ],
    [
        'name' => 'redis',
        'version' => '1.0.0', 
        'description' => 'High-performance Redis-backed queue driver',
        'features' => ['delayed_jobs', 'priority_queues', 'high_throughput'],
        'dependencies' => ['redis']
    ]
]
*/
```

## Job System

### Creating Job Classes

```php
use Glueful\Queue\Job;

class ProcessEmailJob extends Job
{
    /**
     * Execute the job
     */
    public function handle(): void
    {
        $data = $this->getData();
        
        // Send email
        $emailService = container()->get(EmailService::class);
        $emailService->send(
            $data['to'],
            $data['subject'],
            $data['template'],
            $data['variables'] ?? []
        );
        
        // Log success
        logger()->info('Email sent successfully', [
            'job_uuid' => $this->getUuid(),
            'recipient' => $data['to'],
            'template' => $data['template']
        ]);
    }
    
    /**
     * Handle job failure
     */
    public function failed(\Exception $exception): void
    {
        $data = $this->getData();
        
        // Log failure
        logger()->error('Email job failed', [
            'job_uuid' => $this->getUuid(),
            'recipient' => $data['to'],
            'error' => $exception->getMessage(),
            'attempts' => $this->getAttempts()
        ]);
        
        // Notify admin for critical emails
        if ($data['critical'] ?? false) {
            $notificationService = container()->get(NotificationService::class);
            $notificationService->alertAdmin('Critical email job failed', [
                'job_uuid' => $this->getUuid(),
                'error' => $exception->getMessage()
            ]);
        }
    }
    
    /**
     * Configure job settings
     */
    public function getMaxAttempts(): int
    {
        return 5; // Retry up to 5 times
    }
    
    public function getTimeout(): int
    {
        return 120; // 2 minute timeout
    }
}
```

### Advanced Job Features

```php
class ProcessImagesJob extends Job
{
    public function handle(): void
    {
        $data = $this->getData();
        $imageIds = $data['image_ids'];
        
        foreach ($imageIds as $imageId) {
            try {
                $this->processImage($imageId);
            } catch (\Exception $e) {
                // Log individual image failure but continue processing
                logger()->warning('Image processing failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage()
                ]);
                
                // Create separate retry job for failed image
                $queueManager = container()->get(QueueManager::class);
                $queueManager->later(300, ProcessSingleImageJob::class, [
                    'image_id' => $imageId,
                    'retry_count' => ($data['retry_count'] ?? 0) + 1
                ]);
            }
        }
    }
    
    /**
     * Check if job should be retried based on custom logic
     */
    public function shouldRetry(): bool
    {
        $data = $this->getData();
        
        // Don't retry if all images were processed individually
        if ($data['processed_individually'] ?? false) {
            return false;
        }
        
        return parent::shouldRetry();
    }
    
    /**
     * Release job with exponential backoff
     */
    protected function releaseWithBackoff(): void
    {
        $attempts = $this->getAttempts();
        $delay = min(300, pow(2, $attempts) * 10); // Exponential backoff, max 5 minutes
        
        $this->release($delay);
    }
}
```

### Job Serialization and Batch Support

```php
// Job serialization for persistent storage
$job = new ProcessEmailJob(['to' => 'user@example.com']);
$serialized = $job->serialize();
$unserialized = ProcessEmailJob::unserialize($serialized);

// Create job from array (useful for API endpoints)
$jobData = [
    'job' => ProcessEmailJob::class,
    'data' => ['to' => 'user@example.com'],
    'priority' => 5,
    'batchUuid' => 'batch_123'
];
$job = Job::fromArray($jobData);

// Convert job to array for logging/debugging
$jobArray = $job->toArray();
$jobJson = $job->toJson();
```

## Process Management

### Worker Process Management

The ProcessManager handles the lifecycle of worker processes with automatic scaling, health monitoring, and graceful shutdown.

```php
use Glueful\Queue\Process\ProcessManager;
use Glueful\Queue\Process\ProcessFactory;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Glueful\Queue\WorkerOptions;

// Initialize process manager
$processManager = new ProcessManager(
    factory: new ProcessFactory(),
    monitor: new WorkerMonitor(),
    logger: $logger,
    config: [
        'max_workers' => 20,
        'restart_delay' => 5,
        'health_check_interval' => 30
    ]
);

// Spawn workers for different queues
$emailWorkerOptions = new WorkerOptions(
    memory: 256,        // MB
    timeout: 120,       // seconds
    maxJobs: 500,       // jobs per worker
    maxAttempts: 3      // retry attempts
);

$imageWorkerOptions = new WorkerOptions(
    memory: 512,        // Higher memory for image processing
    timeout: 300,       // Longer timeout
    maxJobs: 100        // Fewer jobs due to resource intensity
);

// Spawn workers
$emailWorker = $processManager->spawn('emails', $emailWorkerOptions);
$imageWorker = $processManager->spawn('images', $imageWorkerOptions);

// Scale workers based on load
$processManager->scale(5, 'emails', $emailWorkerOptions);  // Scale to 5 email workers
$processManager->scale(2, 'images', $imageWorkerOptions);  // Scale to 2 image workers
```

### Worker Status and Monitoring

```php
// Get status of all workers
$status = $processManager->getStatus();
/*
[
    [
        'id' => 'worker_abc123',
        'queue' => 'emails',
        'pid' => 12345,
        'status' => 'running',
        'memory_usage' => 145.2,
        'cpu_usage' => 15.6,
        'jobs_processed' => 250,
        'started_at' => '2024-07-02 10:15:30',
        'last_heartbeat' => '2024-07-02 12:45:22'
    ],
    // ... more workers
]
*/

// Monitor worker health
$processManager->monitorHealth(); // Automatically restarts unhealthy workers

// Manual worker restart
$processManager->restart('worker_abc123');

// Graceful shutdown of all workers
$processManager->stopAll(timeout: 60);
```

## Auto-Scaling

### Intelligent Worker Scaling

The AutoScaler automatically adjusts worker count based on queue load, processing rates, and resource utilization.

```php
use Glueful\Queue\Process\AutoScaler;

// Initialize auto-scaler
$autoScaler = new AutoScaler(
    processManager: $processManager,
    queueManager: $queueManager,
    logger: $logger,
    config: [
        'enabled' => true,
        'limits' => [
            'max_workers_per_queue' => 15
        ],
        'auto_scale' => [
            'scale_up_threshold' => 100,      // Queue size threshold
            'scale_down_threshold' => 10,     // Queue size threshold
            'scale_up_step' => 2,             // Workers to add
            'scale_down_step' => 1,           // Workers to remove
            'cooldown_period' => 300          // Seconds between scaling
        ],
        'queues' => [
            'emails' => [
                'auto_scale' => true,
                'min_workers' => 2,
                'max_workers' => 10,
                'scale_up_threshold' => 50,
                'max_wait_time' => 30
            ],
            'images' => [
                'auto_scale' => true,
                'min_workers' => 1,
                'max_workers' => 5,
                'scale_up_threshold' => 20,
                'max_wait_time' => 120
            ]
        ]
    ]
);

// Perform auto-scaling check
$scalingActions = $autoScaler->scale();
/*
[
    [
        'queue' => 'emails',
        'action' => 'scale_up',
        'from' => 3,
        'to' => 5,
        'reason' => 'Queue size (75) > threshold (50), High worker utilization (92%)',
        'metrics' => [
            'queue_size' => 75,
            'avg_worker_utilization' => 92,
            'processing_rate' => 25.5,
            'incoming_rate' => 35.2
        ]
    ]
]
*/

// Force scaling (bypasses cooldown)
$autoScaler->forceScale('images', 3, 'Manual scale for image batch processing');

// Get scaling history
$history = $autoScaler->getScalingHistory('emails');
/*
[
    [
        'queue' => 'emails',
        'timestamp' => 1720035123,
        'from_workers' => 3,
        'to_workers' => 5,
        'reason' => 'Queue size (75) > threshold (50)'
    ],
    // ... more history entries
]
*/
```

### Scaling Metrics and Decision Logic

```php
// The auto-scaler considers multiple metrics for scaling decisions:

// Scale-up conditions:
// - Queue size > scale_up_threshold
// - Incoming rate > processing rate * 1.5
// - Average worker utilization > 85%
// - Average wait time > max_wait_time

// Scale-down conditions:
// - Queue size < scale_down_threshold
// - Average worker utilization < 30% AND wait time < 10 seconds
// - Processing rate > incoming rate * 2 AND queue size < 5

// Custom scaling logic can be implemented by extending AutoScaler
class CustomAutoScaler extends AutoScaler
{
    protected function shouldScaleUp(array $metrics, int $currentWorkers, array $queueConfig): bool
    {
        // Add custom business logic
        $isBusinessHours = (date('H') >= 9 && date('H') <= 17);
        $isWeekday = !in_array(date('w'), [0, 6]);
        
        if ($isBusinessHours && $isWeekday) {
            // More aggressive scaling during business hours
            return $metrics['queue_size'] > 25 || parent::shouldScaleUp($metrics, $currentWorkers, $queueConfig);
        }
        
        return parent::shouldScaleUp($metrics, $currentWorkers, $queueConfig);
    }
}
```

## Batch Processing

### Creating and Managing Batches

```php
use Glueful\Helpers\Utils;

// Create a batch of related jobs
$batchUuid = Utils::generateNanoID();

$jobs = [
    [
        'job' => ProcessEmailJob::class,
        'data' => ['to' => 'user1@example.com', 'template' => 'newsletter'],
        'batch_uuid' => $batchUuid
    ],
    [
        'job' => ProcessEmailJob::class,
        'data' => ['to' => 'user2@example.com', 'template' => 'newsletter'],
        'batch_uuid' => $batchUuid
    ],
    [
        'job' => ProcessEmailJob::class,
        'data' => ['to' => 'user3@example.com', 'template' => 'newsletter'],
        'batch_uuid' => $batchUuid
    ]
];

// Push batch jobs
$uuids = $queueManager->bulk($jobs, 'emails');

// Track batch progress
class BatchProgressTracker
{
    private QueueManager $queueManager;
    
    public function getBatchProgress(string $batchUuid): array
    {
        // Get batch job statistics from database
        $db = container()->get(DatabaseInterface::class);
        
        $completed = $db->count('queue_jobs_completed', ['batch_uuid' => $batchUuid]);
        $failed = $db->count('queue_failed_jobs', ['batch_uuid' => $batchUuid]);
        $pending = $db->count('queue_jobs', ['batch_uuid' => $batchUuid]);
        
        $total = $completed + $failed + $pending;
        
        return [
            'batch_uuid' => $batchUuid,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'pending' => $pending,
            'progress_percentage' => $total > 0 ? ($completed / $total) * 100 : 0,
            'success_rate' => ($completed + $failed) > 0 ? ($completed / ($completed + $failed)) * 100 : 0
        ];
    }
}
```

### Batch Completion Callbacks

```php
class NewsletterBatchJob extends Job
{
    public function handle(): void
    {
        // Process individual newsletter email
        $this->sendNewsletterEmail();
        
        // Check if this is the last job in the batch
        if ($this->isBatchComplete()) {
            $this->handleBatchCompletion();
        }
    }
    
    private function isBatchComplete(): bool
    {
        $batchUuid = $this->getBatchUuid();
        if (!$batchUuid) {
            return true; // Not part of batch
        }
        
        $db = container()->get(DatabaseInterface::class);
        $remaining = $db->count('queue_jobs', ['batch_uuid' => $batchUuid]);
        
        return $remaining <= 1; // This job is the last one
    }
    
    private function handleBatchCompletion(): void
    {
        $batchUuid = $this->getBatchUuid();
        
        // Create completion job
        $queueManager = container()->get(QueueManager::class);
        $queueManager->push(NewsletterBatchCompletedJob::class, [
            'batch_uuid' => $batchUuid,
            'completed_at' => date('Y-m-d H:i:s')
        ]);
    }
}

class NewsletterBatchCompletedJob extends Job
{
    public function handle(): void
    {
        $data = $this->getData();
        $batchUuid = $data['batch_uuid'];
        
        // Generate batch report
        $tracker = new BatchProgressTracker();
        $progress = $tracker->getBatchProgress($batchUuid);
        
        // Send completion notification
        $notificationService = container()->get(NotificationService::class);
        $notificationService->send(
            'admin@example.com',
            'Newsletter Batch Completed',
            'batch-completion',
            [
                'batch_uuid' => $batchUuid,
                'total_emails' => $progress['total'],
                'success_rate' => $progress['success_rate'],
                'completed_at' => $data['completed_at']
            ]
        );
    }
}
```

## Failed Job Handling

### Failed Job Recovery and Analysis

```php
use Glueful\Queue\Failed\FailedJobProvider;

// Get failed job provider
$failedJobProvider = new FailedJobProvider($database);

// Get failed jobs
$failedJobs = $failedJobProvider->all();

foreach ($failedJobs as $failedJob) {
    echo "Failed Job: {$failedJob['uuid']}\n";
    echo "Queue: {$failedJob['queue']}\n";
    echo "Failed At: {$failedJob['failed_at']}\n";
    echo "Exception: {$failedJob['exception']}\n";
    echo "---\n";
}

// Get failed jobs for specific queue
$emailFailures = $failedJobProvider->getByQueue('emails');

// Retry specific failed job
$failedJobProvider->retry('failed_job_uuid_123');

// Retry all failed jobs for a queue
$retryCount = $failedJobProvider->retryQueue('emails');

// Delete old failed jobs
$deletedCount = $failedJobProvider->prune(days: 30);
```

### Custom Failed Job Handlers

```php
class CustomFailedJobHandler
{
    public function handle(JobInterface $job, \Exception $exception): void
    {
        $jobData = $job->getData();
        
        // Categorize failure
        $failureCategory = $this->categorizeFailure($exception);
        
        switch ($failureCategory) {
            case 'network_error':
                // Retry with exponential backoff
                $delay = min(3600, pow(2, $job->getAttempts()) * 60);
                $job->release($delay);
                break;
                
            case 'validation_error':
                // Don't retry validation errors, log for investigation
                $this->logValidationError($job, $exception);
                break;
                
            case 'resource_exhaustion':
                // Retry after longer delay when resources recover
                $job->release(1800); // 30 minutes
                break;
                
            default:
                // Standard retry logic
                if ($job->shouldRetry()) {
                    $job->release(300); // 5 minutes
                } else {
                    $this->moveToFailedJobs($job, $exception);
                }
        }
    }
    
    private function categorizeFailure(\Exception $exception): string
    {
        $message = strtolower($exception->getMessage());
        
        if (strpos($message, 'network') !== false || strpos($message, 'timeout') !== false) {
            return 'network_error';
        }
        
        if (strpos($message, 'validation') !== false || strpos($message, 'invalid') !== false) {
            return 'validation_error';
        }
        
        if (strpos($message, 'memory') !== false || strpos($message, 'disk') !== false) {
            return 'resource_exhaustion';
        }
        
        return 'unknown';
    }
}
```

## Monitoring and Statistics

### Real-time Queue Monitoring

```php
class QueueMonitoringService
{
    private QueueManager $queueManager;
    private ProcessManager $processManager;
    
    public function getSystemOverview(): array
    {
        $overview = [
            'timestamp' => time(),
            'queues' => [],
            'workers' => [],
            'system_health' => 'healthy'
        ];
        
        // Get queue statistics
        $connections = $this->queueManager->getAvailableConnections();
        foreach ($connections as $connection) {
            $stats = $this->queueManager->getStats(null, $connection);
            $health = $this->queueManager->testConnection($connection);
            
            $overview['queues'][$connection] = [
                'stats' => $stats,
                'health' => $health,
                'throughput' => $this->calculateThroughput($connection)
            ];
            
            if (!$health['healthy']) {
                $overview['system_health'] = 'degraded';
            }
        }
        
        // Get worker statistics
        $overview['workers'] = [
            'total' => $this->processManager->getWorkerCount(),
            'by_queue' => $this->getWorkersByQueue(),
            'resource_usage' => $this->getWorkerResourceUsage()
        ];
        
        return $overview;
    }
    
    public function getQueueMetrics(string $queueName, int $timeWindow = 3600): array
    {
        // Get metrics for the last hour by default
        $endTime = time();
        $startTime = $endTime - $timeWindow;
        
        // This would typically query a metrics database
        return [
            'queue' => $queueName,
            'time_window' => $timeWindow,
            'metrics' => [
                'jobs_processed' => $this->getJobsProcessed($queueName, $startTime, $endTime),
                'average_processing_time' => $this->getAverageProcessingTime($queueName, $startTime, $endTime),
                'failure_rate' => $this->getFailureRate($queueName, $startTime, $endTime),
                'throughput_per_minute' => $this->getThroughputPerMinute($queueName, $startTime, $endTime),
                'peak_queue_size' => $this->getPeakQueueSize($queueName, $startTime, $endTime)
            ]
        ];
    }
    
    public function getAlerts(): array
    {
        $alerts = [];
        
        // Check for high failure rates
        $stats = $this->queueManager->getStats();
        if ($stats['failed'] > 0) {
            $failureRate = ($stats['failed'] / ($stats['total'] + $stats['failed'])) * 100;
            if ($failureRate > 10) { // > 10% failure rate
                $alerts[] = [
                    'type' => 'high_failure_rate',
                    'severity' => 'warning',
                    'message' => "Queue failure rate is {$failureRate}%",
                    'value' => $failureRate,
                    'threshold' => 10
                ];
            }
        }
        
        // Check for large queue sizes
        foreach ($this->queueManager->getAvailableConnections() as $connection) {
            $stats = $this->queueManager->getStats(null, $connection);
            if ($stats['pending'] > 1000) {
                $alerts[] = [
                    'type' => 'large_queue_size',
                    'severity' => 'warning',
                    'message' => "Queue {$connection} has {$stats['pending']} pending jobs",
                    'value' => $stats['pending'],
                    'threshold' => 1000
                ];
            }
        }
        
        // Check for stalled workers
        $stalledWorkers = $this->getStalledWorkers();
        if (!empty($stalledWorkers)) {
            $alerts[] = [
                'type' => 'stalled_workers',
                'severity' => 'error',
                'message' => count($stalledWorkers) . " workers appear to be stalled",
                'workers' => $stalledWorkers
            ];
        }
        
        return $alerts;
    }
}
```

### Performance Analytics

```php
class QueuePerformanceAnalyzer
{
    public function analyzeQueuePerformance(string $queueName, array $timeRange): array
    {
        return [
            'queue' => $queueName,
            'analysis_period' => $timeRange,
            'performance_metrics' => [
                'average_throughput' => $this->calculateAverageThroughput($queueName, $timeRange),
                'peak_throughput' => $this->calculatePeakThroughput($queueName, $timeRange),
                'average_latency' => $this->calculateAverageLatency($queueName, $timeRange),
                'p95_latency' => $this->calculatePercentileLatency($queueName, $timeRange, 95),
                'p99_latency' => $this->calculatePercentileLatency($queueName, $timeRange, 99)
            ],
            'bottlenecks' => $this->identifyBottlenecks($queueName, $timeRange),
            'recommendations' => $this->generateRecommendations($queueName, $timeRange)
        ];
    }
    
    private function identifyBottlenecks(string $queueName, array $timeRange): array
    {
        $bottlenecks = [];
        
        // Check for worker utilization issues
        $workerUtilization = $this->getWorkerUtilization($queueName, $timeRange);
        if ($workerUtilization > 90) {
            $bottlenecks[] = [
                'type' => 'worker_saturation',
                'description' => 'Worker utilization is consistently above 90%',
                'impact' => 'high',
                'suggestion' => 'Consider increasing the number of workers'
            ];
        }
        
        // Check for memory pressure
        $avgMemoryUsage = $this->getAverageMemoryUsage($queueName, $timeRange);
        if ($avgMemoryUsage > 80) { // > 80% of limit
            $bottlenecks[] = [
                'type' => 'memory_pressure',
                'description' => 'Workers are consistently using high memory',
                'impact' => 'medium',
                'suggestion' => 'Optimize job memory usage or increase worker memory limits'
            ];
        }
        
        // Check for database/Redis performance
        $avgResponseTime = $this->getAverageStorageResponseTime($queueName, $timeRange);
        if ($avgResponseTime > 100) { // > 100ms
            $bottlenecks[] = [
                'type' => 'storage_latency',
                'description' => 'Storage operations are slower than optimal',
                'impact' => 'medium',
                'suggestion' => 'Consider optimizing database queries or Redis configuration'
            ];
        }
        
        return $bottlenecks;
    }
}
```

## CLI Commands

### Basic Queue Commands

```bash
# Start queue worker
php glueful queue:work

# Start worker for specific queue
php glueful queue:work --queue=emails

# Start worker with custom options
php glueful queue:work --memory=512 --timeout=300 --max-jobs=100

# Start worker with auto-scaling
php glueful queue:work --auto-scale --max-workers=10

# Listen for jobs (restart on code changes)
php glueful queue:listen --queue=emails
```

### Queue Management Commands

```bash
# Get queue statistics
php glueful queue:stats

# Get stats for specific queue
php glueful queue:stats --queue=emails

# Purge queue (remove all jobs)
php glueful queue:purge --queue=emails

# Purge all queues
php glueful queue:purge --all

# Test queue connections
php glueful queue:health

# Restart all workers
php glueful queue:restart
```

### Failed Job Management

```bash
# List failed jobs
php glueful queue:failed

# Retry specific failed job
php glueful queue:retry failed_job_uuid_123

# Retry all failed jobs
php glueful queue:retry --all

# Retry failed jobs for specific queue
php glueful queue:retry --queue=emails

# Delete old failed jobs
php glueful queue:prune --days=30

# Flush all failed jobs
php glueful queue:flush
```

### Auto-Scaling Commands

```bash
# Start auto-scaler daemon
php glueful queue:autoscale

# Force scale specific queue
php glueful queue:scale emails --workers=5

# Get scaling history
php glueful queue:scaling-history

# Get current worker status
php glueful queue:workers
```

## Configuration

### Main Queue Configuration

```php
// config/queue.php
return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => env('QUEUE_TABLE', 'queue_jobs'),
            'failed_table' => env('QUEUE_FAILED_TABLE', 'queue_failed_jobs'),
            'retry_after' => env('QUEUE_RETRY_AFTER', 90),
            'batch_size' => env('QUEUE_BATCH_SIZE', 100)
        ],
        
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_QUEUE_DB', 0),
            'prefix' => env('REDIS_QUEUE_PREFIX', 'glueful:queue:'),
            'retry_after' => env('QUEUE_RETRY_AFTER', 90),
            'job_expiration' => env('QUEUE_JOB_EXPIRATION', 3600),
            'persistent' => env('REDIS_PERSISTENT', false)
        ]
    ],
    
    'worker_options' => [
        'memory' => env('QUEUE_WORKER_MEMORY', 128),        // MB
        'timeout' => env('QUEUE_WORKER_TIMEOUT', 60),       // seconds
        'max_jobs' => env('QUEUE_WORKER_MAX_JOBS', 1000),   // jobs per worker
        'max_attempts' => env('QUEUE_MAX_ATTEMPTS', 3),     // retry attempts
        'sleep' => env('QUEUE_WORKER_SLEEP', 3),            // seconds between checks
        'rest' => env('QUEUE_WORKER_REST', 0)               // seconds to rest after jobs
    ],
    
    'auto_scaling' => [
        'enabled' => env('QUEUE_AUTO_SCALE', false),
        'limits' => [
            'max_workers_per_queue' => env('QUEUE_MAX_WORKERS_PER_QUEUE', 10),
            'max_total_workers' => env('QUEUE_MAX_TOTAL_WORKERS', 50)
        ],
        'thresholds' => [
            'scale_up_threshold' => env('QUEUE_SCALE_UP_THRESHOLD', 100),
            'scale_down_threshold' => env('QUEUE_SCALE_DOWN_THRESHOLD', 10),
            'scale_up_step' => env('QUEUE_SCALE_UP_STEP', 2),
            'scale_down_step' => env('QUEUE_SCALE_DOWN_STEP', 1),
            'cooldown_period' => env('QUEUE_COOLDOWN_PERIOD', 300)
        ]
    ],
    
    'monitoring' => [
        'enabled' => env('QUEUE_MONITORING', true),
        'metrics_retention' => env('QUEUE_METRICS_RETENTION', 7), // days
        'health_check_interval' => env('QUEUE_HEALTH_CHECK_INTERVAL', 60), // seconds
        'alert_thresholds' => [
            'failure_rate' => env('QUEUE_ALERT_FAILURE_RATE', 10), // percentage
            'queue_size' => env('QUEUE_ALERT_QUEUE_SIZE', 1000),   // number of jobs
            'worker_memory' => env('QUEUE_ALERT_WORKER_MEMORY', 90) // percentage
        ]
    ]
];
```

### Queue-Specific Configuration

```php
// config/queue_settings.php
return [
    'queues' => [
        'emails' => [
            'auto_scale' => true,
            'min_workers' => 2,
            'max_workers' => 8,
            'scale_up_threshold' => 50,
            'scale_down_threshold' => 5,
            'worker_options' => [
                'memory' => 256,
                'timeout' => 120,
                'max_jobs' => 500
            ]
        ],
        
        'images' => [
            'auto_scale' => true,
            'min_workers' => 1,
            'max_workers' => 4,
            'scale_up_threshold' => 20,
            'scale_down_threshold' => 2,
            'worker_options' => [
                'memory' => 512,
                'timeout' => 300,
                'max_jobs' => 50
            ]
        ],
        
        'reports' => [
            'auto_scale' => false,
            'workers' => 1,
            'worker_options' => [
                'memory' => 1024,
                'timeout' => 1800,
                'max_jobs' => 10
            ]
        ]
    ]
];
```

### Environment Variables

```env
# Queue Configuration
QUEUE_CONNECTION=redis
QUEUE_TABLE=queue_jobs
QUEUE_FAILED_TABLE=queue_failed_jobs
QUEUE_RETRY_AFTER=90
QUEUE_BATCH_SIZE=100

# Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_QUEUE_DB=0
REDIS_QUEUE_PREFIX=glueful:queue:
REDIS_PERSISTENT=false

# Worker Configuration
QUEUE_WORKER_MEMORY=128
QUEUE_WORKER_TIMEOUT=60
QUEUE_WORKER_MAX_JOBS=1000
QUEUE_MAX_ATTEMPTS=3
QUEUE_WORKER_SLEEP=3

# Auto-Scaling
QUEUE_AUTO_SCALE=false
QUEUE_MAX_WORKERS_PER_QUEUE=10
QUEUE_SCALE_UP_THRESHOLD=100
QUEUE_SCALE_DOWN_THRESHOLD=10
QUEUE_COOLDOWN_PERIOD=300

# Monitoring
QUEUE_MONITORING=true
QUEUE_HEALTH_CHECK_INTERVAL=60
QUEUE_ALERT_FAILURE_RATE=10
QUEUE_ALERT_QUEUE_SIZE=1000
```

## Production Deployment

### High-Availability Setup

```bash
# Use a process manager like Supervisor for production
# /etc/supervisor/conf.d/glueful-workers.conf

[program:glueful-worker-emails]
command=php /path/to/glueful/glueful queue:work --queue=emails --memory=256 --timeout=120
process_name=%(program_name)s_%(process_num)02d
numprocs=4
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/glueful-queue-emails.log

[program:glueful-worker-images]
command=php /path/to/glueful/glueful queue:work --queue=images --memory=512 --timeout=300
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/glueful-queue-images.log

[program:glueful-autoscaler]
command=php /path/to/glueful/glueful queue:autoscale
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/glueful-autoscaler.log
```

### Performance Optimization

```php
// Production optimization tips:

// 1. Use Redis for high-throughput workloads
$config['default'] = 'redis';

// 2. Tune worker settings for your workload
$config['worker_options'] = [
    'memory' => 512,        // Increase for memory-intensive jobs
    'timeout' => 120,       // Increase for long-running jobs
    'max_jobs' => 500,      // Balance between efficiency and memory usage
    'sleep' => 1           // Reduce for high-frequency workloads
];

// 3. Enable auto-scaling for variable workloads
$config['auto_scaling']['enabled'] = true;

// 4. Use connection pooling for database drivers
$config['connections']['database']['pool_size'] = 20;

// 5. Configure appropriate retry settings
$config['connections']['redis']['retry_after'] = 60; // Faster retry for Redis

// 6. Enable monitoring for production insights
$config['monitoring']['enabled'] = true;
```

### Monitoring and Alerting Setup

```php
// Set up monitoring webhook for queue alerts
class QueueAlertingService
{
    public function checkAndAlert(): void
    {
        $monitor = new QueueMonitoringService();
        $alerts = $monitor->getAlerts();
        
        foreach ($alerts as $alert) {
            match ($alert['severity']) {
                'error' => $this->sendCriticalAlert($alert),
                'warning' => $this->sendWarningAlert($alert),
                default => $this->logAlert($alert)
            };
        }
    }
    
    private function sendCriticalAlert(array $alert): void
    {
        // Send to PagerDuty, Slack, email, etc.
        $this->notificationService->send([
            'channels' => ['pagerduty', 'slack'],
            'message' => "CRITICAL: Queue Alert - {$alert['message']}",
            'data' => $alert
        ]);
    }
}

// Run alerting check every 5 minutes via cron
// */5 * * * * php /path/to/glueful/glueful queue:check-alerts
```

## Summary

Glueful's queue system provides enterprise-grade background job processing with:

- **Multi-Driver Support**: Database and Redis drivers with ACID compliance and high performance
- **Auto-Scaling**: Intelligent worker scaling based on real-time metrics and load patterns  
- **Process Management**: Advanced worker lifecycle management with health monitoring
- **Batch Processing**: Efficient bulk operations with progress tracking and completion callbacks
- **Failed Job Handling**: Comprehensive retry mechanisms and failure analysis
- **Real-time Monitoring**: Queue statistics, worker metrics, and performance analytics
- **Production Ready**: High-availability setup, performance optimization, and comprehensive alerting

The system is designed to scale from simple background tasks to high-throughput, mission-critical job processing while providing detailed insights and automatic optimization capabilities.