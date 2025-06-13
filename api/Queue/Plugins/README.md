# Queue Plugin System

The Glueful Queue System supports a comprehensive plugin architecture that allows third-party developers to extend queue functionality without modifying core code.

## Plugin Structure

Plugins are PHP files that return an array configuration. They can be placed in:
- `/extensions/*/queue-plugin.php` - Extension-based plugins
- `/vendor/*/queue-plugins/*/plugin.php` - Composer package plugins

## Plugin Components

### 1. Basic Plugin Structure

```php
return [
    'name' => 'My Queue Plugin',        // Required
    'version' => '1.0.0',              // Optional
    'author' => 'Your Name',           // Optional
    'description' => 'Description',     // Optional
    'drivers' => [...],                // Custom queue drivers
    'listeners' => [...],              // Event listeners
    'hooks' => [...],                  // System hooks
    'init' => function($pm) {...}      // Initialization callback
];
```

### 2. Registering Custom Drivers

```php
'drivers' => [
    [
        'name' => 'custom-driver',
        'class' => 'MyPlugin\\Queue\\CustomDriver',
        'description' => 'Custom queue driver'
    ]
]
```

### 3. Event Listeners

Plugins can listen to queue system events:

```php
'listeners' => [
    // Job lifecycle events
    'job.pushing' => function($job) { },
    'job.pushed' => function($job) { },
    'job.processing' => function($job) { },
    'job.processed' => function($job) { },
    'job.failed' => function($job, $exception) { },
    'job.released' => function($job) { },
    
    // Worker events
    'worker.starting' => function($worker) { },
    'worker.stopping' => function($worker) { },
    'worker.heartbeat' => function($worker) { },
    
    // Queue events
    'queue.empty' => function($queue) { },
    'queue.purged' => function($queue, $count) { },
    
    // Wildcard support
    'job.*' => function($data, $event) { }
]
```

### 4. Hook System

Hooks allow plugins to modify queue behavior:

```php
'hooks' => [
    // Job hooks
    'before_push' => function($jobData) {
        // Modify job data before pushing
        return $jobData;
    },
    
    'after_push' => function($jobId) {
        // Called after job is pushed
    },
    
    // Driver hooks
    'driver_selection' => function($driverName) {
        // Override driver selection
        return $driverName;
    },
    
    // Worker hooks
    'worker_config' => function($config) {
        // Modify worker configuration
        return $config;
    }
]
```

### 5. Initialization

The init callback runs when the plugin is loaded:

```php
'init' => function($pluginManager) {
    // Access plugin manager features
    $dispatcher = $pluginManager->getEventDispatcher();
    
    // Register dynamic listeners
    $dispatcher->listen('custom.event', function($data) {
        // Handle event
    });
    
    // Check for dependencies
    if (!$pluginManager->hasPlugin('Required Plugin')) {
        throw new Exception('Required plugin not found');
    }
}
```

## Creating a Custom Driver Plugin

### Step 1: Create Driver Class

```php
namespace MyPlugin\Queue;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\DriverInfo;
use Glueful\Queue\Contracts\HealthStatus;

class CustomDriver implements QueueDriverInterface
{
    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            name: 'custom',
            version: '1.0.0',
            author: 'Your Name',
            description: 'Custom queue driver',
            supportedFeatures: ['delayed_jobs', 'priorities'],
            requiredDependencies: []
        );
    }
    
    public function initialize(array $config): void
    {
        // Initialize driver with config
    }
    
    public function push(string $job, array $data = [], ?string $queue = null): string
    {
        // Implement job pushing
        return 'job-id';
    }
    
    // Implement other required methods...
}
```

### Step 2: Create Plugin File

```php
// extensions/my-plugin/queue-plugin.php
return [
    'name' => 'Custom Queue Driver',
    'version' => '1.0.0',
    'drivers' => [
        [
            'name' => 'custom',
            'class' => 'MyPlugin\\Queue\\CustomDriver'
        ]
    ],
    'listeners' => [
        'job.pushed' => function($job) {
            error_log("Job pushed via custom driver: " . $job->getUuid());
        }
    ]
];
```

### Step 3: Use the Driver

```php
// In your application
$queueManager = new QueueManager();
$queueManager->connection('custom')->push('ProcessJob', ['data' => 'value']);
```

## Plugin Events Reference

### Job Events
- `job.pushing` - Before job is pushed to queue
- `job.pushed` - After job is pushed to queue
- `job.processing` - Before job execution starts
- `job.processed` - After job completes successfully
- `job.failed` - When job fails
- `job.released` - When job is released back to queue
- `job.deleted` - When job is deleted from queue

### Worker Events
- `worker.starting` - Worker process starting
- `worker.stopping` - Worker process stopping
- `worker.heartbeat` - Worker heartbeat tick
- `worker.paused` - Worker paused
- `worker.resumed` - Worker resumed

### Queue Events
- `queue.empty` - Queue has no jobs
- `queue.full` - Queue reached capacity
- `queue.purged` - Queue was purged
- `queue.stats` - Queue statistics updated

### Batch Events
- `batch.created` - Batch was created
- `batch.processed` - Batch job processed
- `batch.completed` - Batch completed
- `batch.failed` - Batch failed
- `batch.cancelled` - Batch was cancelled

## Best Practices

1. **Namespace Your Plugin**: Use a unique namespace to avoid conflicts
2. **Handle Errors Gracefully**: Don't let plugin errors break the queue system
3. **Document Dependencies**: Clearly state what your plugin requires
4. **Version Your Plugin**: Use semantic versioning
5. **Test Thoroughly**: Test with different queue drivers and configurations
6. **Performance**: Be mindful of performance impact in event listeners
7. **Security**: Validate and sanitize all inputs

## Example: Monitoring Plugin

```php
return [
    'name' => 'Queue Monitor',
    'version' => '1.0.0',
    'author' => 'Monitoring Team',
    'description' => 'Monitors queue performance and sends alerts',
    
    'listeners' => [
        'job.processing' => function($job) {
            // Start timing
            Cache::put("job.{$job->getUuid()}.start", microtime(true));
        },
        
        'job.processed' => function($job) {
            // Calculate execution time
            $start = Cache::get("job.{$job->getUuid()}.start");
            $duration = microtime(true) - $start;
            
            // Send metrics
            Metrics::record('job.duration', $duration, [
                'queue' => $job->getQueue(),
                'job' => get_class($job)
            ]);
            
            Cache::forget("job.{$job->getUuid()}.start");
        },
        
        'job.failed' => function($job, $exception) {
            // Send alert
            Alert::send('Job failed', [
                'job' => $job->getUuid(),
                'error' => $exception->getMessage()
            ]);
        },
        
        'queue.empty' => function($queue) {
            // Log queue empty event
            Log::info("Queue {$queue} is empty");
        }
    ],
    
    'hooks' => [
        'worker_config' => function($config) {
            // Add monitoring configuration
            $config['monitor'] = [
                'enabled' => true,
                'interval' => 60
            ];
            return $config;
        }
    ],
    
    'init' => function($pluginManager) {
        // Set up monitoring dashboard route
        Route::get('/queue/monitor', 'MonitorController@dashboard');
    }
];
```

## Debugging Plugins

Enable debug logging to troubleshoot plugins:

```php
// In your plugin's init callback
'init' => function($pluginManager) {
    error_log("Plugin loading: " . __FILE__);
    
    // List all loaded plugins
    $plugins = $pluginManager->getPlugins();
    error_log("Loaded plugins: " . implode(', ', array_keys($plugins)));
}
```

## Plugin Distribution

Plugins can be distributed as:

1. **Composer Packages**: Create a composer package with your plugin
2. **Extensions**: Bundle as a Glueful extension
3. **Standalone Files**: Single file plugins for simple functionality

For Composer packages, use this structure:
```
my-queue-plugin/
├── composer.json
├── src/
│   └── Drivers/
│       └── CustomDriver.php
└── queue-plugins/
    └── my-plugin/
        └── plugin.php
```