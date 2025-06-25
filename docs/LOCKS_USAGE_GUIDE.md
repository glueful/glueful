# Symfony Lock Integration - Usage Guide

## Overview

Glueful now includes Symfony Lock component integration for distributed locking. This prevents race conditions in queue workers, scheduled tasks, and other concurrent processes.

## Quick Start

### Basic Lock Usage

```php
use Glueful\Lock\LockManagerInterface;

// Get lock manager from container
$lockManager = container()->get(LockManagerInterface::class);

// Simple lock
$lock = $lockManager->createLock('my-resource');
if ($lock->acquire()) {
    try {
        // Critical section - only one process can execute this
        processImportantTask();
    } finally {
        $lock->release();
    }
}
```

### Execute with Lock (Recommended)

```php
// Automatic lock management with exception safety
$result = $lockManager->executeWithLock('import-users', function() {
    return importUsersFromCSV();
}, 300); // 5 minute TTL
```

### Wait and Execute

```php
// Wait up to 10 seconds for lock, then execute
$result = $lockManager->waitAndExecute('process-payments', function() {
    return processPayments();
}, 10.0, 300); // maxWait: 10s, TTL: 5min
```

## Configuration

### Lock Stores

Configure lock storage in `config/lock.php`:

```php
return [
    'default' => env('LOCK_DRIVER', 'file'),
    
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'prefix' => 'glueful_lock_',
        ],
        
        'database' => [
            'driver' => 'database',
            'table' => 'locks',
        ],
        
        'file' => [
            'driver' => 'file',
            'path' => 'framework/locks',
        ],
    ],
];
```

### Environment Variables

```env
# Lock configuration
LOCK_DRIVER=redis
LOCK_PREFIX=myapp_lock_
LOCK_TTL=300
```

## Common Use Cases

### 1. Queue Worker Coordination

Automatically integrated in `WorkCommand`:

```php
// In WorkCommand - prevents duplicate workers
$this->lockManager->executeWithLock("queue:manager:{$queue}:" . gethostname(), function() {
    $this->processManager->scale($workerCount, $queue, $workerOptions);
}, 60);
```

### 2. Scheduled Task Locks

Automatically integrated in `JobScheduler`:

```php
// In JobScheduler - prevents overlapping executions
$lockResource = "scheduler:job:{$jobName}";
$this->lockManager->executeWithLock($lockResource, function() use ($job) {
    return $this->executeJob($job);
}, 3600); // 1 hour max execution
```

### 3. Data Processing Locks

```php
// Prevent concurrent data imports
$lockManager->executeWithLock('import:customers', function() {
    $this->importCustomerData();
    $this->updateSearchIndex();
    $this->clearCache();
});
```

### 4. Cache Warming Locks

```php
// Prevent multiple cache warming processes
$lockManager->executeWithLock('cache:warm:products', function() {
    $this->warmProductCache();
}, 1800); // 30 minutes
```

### 5. File Processing Locks

```php
// Process files one at a time
foreach ($files as $file) {
    $lockKey = 'file:process:' . basename($file);
    
    try {
        $lockManager->executeWithLock($lockKey, function() use ($file) {
            return $this->processFile($file);
        }, 3600);
        
        echo "Processed: {$file}\n";
        
    } catch (LockConflictedException $e) {
        echo "Skipping {$file} - already processing\n";
    }
}
```

## Advanced Usage

### Manual Lock Management

```php
$lock = $lockManager->createLock('my-resource', 300, true); // TTL, auto-release

if ($lock->acquire()) {
    try {
        // Check remaining time
        $remaining = $lock->getRemainingLifetime();
        echo "Lock expires in {$remaining} seconds\n";
        
        // Refresh lock if needed
        if ($remaining < 60) {
            $lock->refresh(300); // Extend by 5 minutes
        }
        
        // Do work
        performLongRunningTask();
        
    } finally {
        $lock->release();
    }
} else {
    echo "Could not acquire lock\n";
}
```

### Lock Status Checking

```php
// Check if resource is locked (non-blocking)
if ($lockManager->isLocked('my-resource')) {
    echo "Resource is currently locked\n";
} else {
    // Safe to proceed
}
```

### Blocking Lock Acquisition

```php
$lock = $lockManager->createLock('resource');

// Block until lock is acquired
if ($lock->acquire(true)) { // blocking = true
    try {
        // Work here
    } finally {
        $lock->release();
    }
}
```

## Lock Naming Conventions

Use descriptive, hierarchical lock names:

```php
// Good examples
"queue:worker:{$queue}:{$workerId}"
"scheduler:job:{$jobName}"
"import:users:batch:{$batchId}"
"cache:warm:{$cacheKey}"
"process:payments:daily"

// Avoid generic names
"lock"
"process"
"task"
```

## Error Handling

### Handle Lock Conflicts

```php
use Symfony\Component\Lock\Exception\LockConflictedException;

try {
    $lockManager->executeWithLock('my-resource', function() {
        // Critical section
    });
} catch (LockConflictedException $e) {
    // Lock could not be acquired
    $this->logger->info('Resource busy, skipping execution');
} catch (\Exception $e) {
    // Other errors
    $this->logger->error('Execution failed: ' . $e->getMessage());
}
```

### Timeout Handling

```php
try {
    $result = $lockManager->waitAndExecute('resource', function() {
        return doWork();
    }, 30.0); // 30 second timeout
    
} catch (LockConflictedException $e) {
    echo "Timeout: Could not acquire lock within 30 seconds\n";
}
```

## Performance Tips

### 1. Use Appropriate TTLs

```php
// Short tasks
$lockManager->executeWithLock('quick-task', $callback, 60);

// Long-running imports
$lockManager->executeWithLock('data-import', $callback, 3600);

// Background jobs
$lockManager->executeWithLock('background-job', $callback, 7200);
```

### 2. Choose the Right Store

- **File**: Development, single-server setups
- **Redis**: Multi-server, high-performance (recommended)
- **Database**: When Redis isn't available

### 3. Use Descriptive Lock Names

```php
// Include relevant identifiers
$lockKey = "user:export:{$userId}:format:{$format}";
$lockKey = "report:generate:{$reportType}:date:{$date}";
```

### 4. Monitor Lock Usage

```php
// Log lock acquisition for monitoring
$lockManager->executeWithLock($resource, function() use ($resource) {
    $this->logger->info("Acquired lock: {$resource}");
    return $this->doWork();
});
```

## CLI Commands

### Queue Worker with Locks

```bash
# Workers automatically coordinate using locks
php glueful queue:work --workers=3 --queue=default

# Scale workers safely
php glueful queue:work scale --count=5 --queue=processing
```

### Scheduler with Locks

```bash
# Run scheduled jobs (prevents overlaps)
php glueful queue:scheduler run

# Run in worker mode
php glueful queue:scheduler work --interval=60
```

## Troubleshooting

### 1. Locks Not Working

Check your configuration:

```bash
# Verify lock configuration
php glueful config:show lock

# Test lock store connectivity
php glueful system:check
```

### 2. Stuck Locks

Locks automatically expire based on TTL, but you can check:

```php
// Check lock expiration
$lock = $lockManager->createLock('stuck-resource');
if ($lock->isExpired()) {
    echo "Lock has expired\n";
}
```

### 3. Performance Issues

- Use Redis for better performance
- Optimize lock TTLs
- Avoid creating too many locks

### 4. Database Locks Table

Ensure the locks table exists:

```bash
php glueful migrate run
```

## Best Practices

1. **Always use try/finally or executeWithLock()**
2. **Set appropriate TTLs for your use case**
3. **Use descriptive lock names with context**
4. **Handle LockConflictedException gracefully**
5. **Monitor lock usage in production**
6. **Use Redis store for multi-server setups**
7. **Don't hold locks longer than necessary**
8. **Log lock operations for debugging**

## Integration Examples

### Custom Service with Locks

```php
use Glueful\Lock\LockManagerInterface;

class DataSyncService
{
    public function __construct(
        private LockManagerInterface $lockManager
    ) {}
    
    public function syncUsers(): void
    {
        $this->lockManager->executeWithLock('sync:users', function() {
            $this->fetchUsersFromAPI();
            $this->updateDatabase();
            $this->invalidateCache();
        }, 1800); // 30 minutes
    }
    
    public function syncInBatches(array $userIds): void
    {
        foreach (array_chunk($userIds, 100) as $batch) {
            $batchId = md5(implode(',', $batch));
            
            $this->lockManager->executeWithLock("sync:batch:{$batchId}", function() use ($batch) {
                $this->syncUserBatch($batch);
            }, 300);
        }
    }
}
```

This integration provides robust distributed locking for all your critical sections!