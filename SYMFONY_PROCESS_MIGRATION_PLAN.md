# Symfony Process Migration Plan for Glueful Queue System

## Overview

This document outlines the migration plan for integrating Symfony Process component into Glueful's queue system to enable better process management, monitoring, and multi-worker capabilities.

## Current State Analysis

### Existing Queue Architecture
- **Single-process workers**: Each worker runs as a standalone PHP process
- **External supervision**: Relies on Supervisor/systemd for multi-worker setups
- **Manual scaling**: No built-in process spawning or management
- **Basic monitoring**: Limited to heartbeat system via WorkerMonitor

### Pain Points
1. No built-in multi-worker management
2. Difficult local development (requires Supervisor setup)
3. Limited process control (no real-time output, PID tracking)
4. Platform-specific deployment configurations
5. No programmatic worker scaling

## Migration Goals

1. **Enhanced Process Control**
   - Start/stop workers programmatically
   - Real-time output monitoring
   - Process health checks and auto-restart

2. **Developer Experience**
   - Easy multi-worker setup in development
   - Cross-platform compatibility (Windows/Mac/Linux)
   - Better debugging with real-time output

3. **Production Features**
   - Dynamic worker scaling
   - Better resource monitoring
   - Graceful shutdown coordination
   - Process pool management

## Architecture Design

### New Components

#### 1. ProcessManager Service
```php
namespace Glueful\Queue\Process;

class ProcessManager
{
    private array $workers = [];
    private ProcessFactory $factory;
    private WorkerMonitor $monitor;
    
    public function spawn(string $queue, WorkerOptions $options): WorkerProcess
    public function scale(int $count, string $queue = 'default'): void
    public function stopAll(int $timeout = 30): void
    public function restart(string $workerId): void
    public function getStatus(): array
}
```

#### 2. WorkerProcess Wrapper
```php
namespace Glueful\Queue\Process;

class WorkerProcess
{
    private Process $process;
    private string $workerId;
    private WorkerOptions $options;
    
    public function start(): void
    public function stop(int $timeout = 30): void
    public function restart(): void
    public function isRunning(): bool
    public function getOutput(): string
    public function getMemoryUsage(): int
}
```

#### 3. ProcessFactory
```php
namespace Glueful\Queue\Process;

class ProcessFactory
{
    public function create(string $command, array $args, WorkerOptions $options): Process
    public function createWorker(string $queue, WorkerOptions $options): WorkerProcess
}
```

### Integration Points

1. **QueueCommand Enhancement**
   - Add `queue:work:spawn` command for multi-worker management
   - Enhance `queue:work` with `--workers` option
   - Add `queue:work:scale` for dynamic scaling

2. **Monitoring Integration**
   - Extend WorkerMonitor to track Symfony Process metrics
   - Add process-level health checks
   - Real-time output streaming to logs

3. **Configuration**
   - New `config/process.php` for process defaults
   - Environment variables for worker counts
   - Queue-specific worker configurations

## Implementation Phases

### Phase 1: Foundation (Week 1-2)
1. **Add Symfony Process dependency**
   ```bash
   composer require symfony/process
   ```

2. **Create base process management classes**
   - ProcessManager service
   - WorkerProcess wrapper
   - ProcessFactory

3. **Basic integration tests**
   - Single process spawning
   - Process monitoring
   - Graceful shutdown

### Phase 2: Queue Integration (Week 3-4)
1. **Enhance QueueCommand**
   - Add multi-worker support
   - Implement spawn/scale commands
   - Process status reporting

2. **Update WorkerMonitor**
   - Track process-spawned workers
   - Aggregate metrics across processes
   - Health check integration

3. **Configuration system**
   - Process configuration file
   - Environment variable support
   - Per-queue worker settings

### Phase 3: Advanced Features (Week 5-6)
1. **Auto-scaling capabilities**
   - Load-based scaling
   - Schedule-based scaling
   - Resource limit awareness

2. **Enhanced monitoring**
   - Real-time output streaming
   - Process resource tracking
   - Performance metrics

3. **Developer tools**
   - Web UI for worker management
   - Debug mode with verbose output
   - Process visualization

### Phase 4: Migration & Documentation (Week 7-8)
1. **Backward compatibility**
   - Maintain existing single-worker mode
   - Supervisor configuration generator
   - Migration guide for deployments

2. **Documentation**
   - Update CLAUDE.md with new commands
   - Developer guide for process management
   - Production deployment guide

3. **Testing & optimization**
   - Load testing with multiple workers
   - Memory leak detection
   - Performance benchmarking

## Backward Compatibility Strategy

### 1. Dual-Mode Operation
- Default: Current single-worker behavior
- Opt-in: New process management via flag/config
- Gradual migration path

### 2. Configuration Compatibility
```php
// config/queue.php
'process_management' => [
    'enabled' => env('QUEUE_PROCESS_MANAGEMENT', false),
    'default_workers' => env('QUEUE_DEFAULT_WORKERS', 1),
    'max_workers' => env('QUEUE_MAX_WORKERS', 10),
    'fallback_to_single' => true,
],
```

### 3. Command Compatibility
- `queue:work` - Maintains current behavior
- `queue:work --workers=4` - New multi-worker mode
- `queue:work:spawn` - Advanced process management

## Risk Mitigation

### Technical Risks
1. **Memory leaks in long-running processes**
   - Mitigation: Automatic worker restart after X jobs
   - Memory monitoring and limits

2. **Process zombie/orphan issues**
   - Mitigation: Proper signal handling
   - Process group management

3. **Platform compatibility**
   - Mitigation: Extensive testing on Windows/Mac/Linux
   - Fallback mechanisms for unsupported features

### Operational Risks
1. **Breaking existing deployments**
   - Mitigation: Opt-in approach
   - Comprehensive migration guide

2. **Performance regression**
   - Mitigation: Benchmark before/after
   - Configurable process limits

## Success Metrics

1. **Developer Experience**
   - Time to setup multi-worker environment: < 1 minute
   - Cross-platform success rate: > 95%

2. **Performance**
   - Process spawn time: < 100ms
   - Memory overhead per worker: < 5MB
   - CPU overhead for management: < 2%

3. **Reliability**
   - Worker crash recovery: < 5 seconds
   - Graceful shutdown success: 100%
   - No orphan processes

## Timeline

- **Week 1-2**: Foundation implementation
- **Week 3-4**: Queue integration
- **Week 5-6**: Advanced features
- **Week 7-8**: Migration and documentation
- **Week 9-10**: Testing and rollout

## Code Examples

### Basic Usage
```php
// Start multiple workers
$manager = container()->get(ProcessManager::class);
$manager->scale(4, 'default');
$manager->scale(2, 'high-priority');

// Monitor workers
$status = $manager->getStatus();
foreach ($status as $worker) {
    echo "Worker {$worker['id']}: {$worker['status']}\n";
    echo "Memory: {$worker['memory_usage']}MB\n";
    echo "Jobs processed: {$worker['jobs_processed']}\n";
}

// Graceful shutdown
$manager->stopAll(30); // 30 second timeout
```

### Configuration Example
```php
// config/process.php
return [
    'defaults' => [
        'timeout' => 300,
        'memory_limit' => 128,
        'max_jobs' => 1000,
    ],
    'queues' => [
        'default' => [
            'workers' => env('DEFAULT_WORKERS', 2),
            'max_workers' => 10,
        ],
        'high-priority' => [
            'workers' => env('HIGH_PRIORITY_WORKERS', 4),
            'timeout' => 60,
        ],
    ],
];
```

### CLI Usage
```bash
# Start workers with process management
php glueful queue:work --workers=4 --queue=default,high

# Scale workers dynamically
php glueful queue:work:scale 6 --queue=default

# Check worker status
php glueful queue:work:status

# Restart all workers
php glueful queue:work:restart --all
```

## Conclusion

This migration plan provides a path to modernize Glueful's queue system with robust process management capabilities while maintaining backward compatibility and minimizing risk. The phased approach allows for incremental implementation and testing, ensuring a smooth transition for both development and production environments.