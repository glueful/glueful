# Memory Pool

## Overview

The `MemoryPool` class provides an efficient object storage mechanism that helps reduce memory overhead and fragmentation when working with large or frequently used objects. This tool is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Object Lifecycle](#object-lifecycle)
- [Memory Management](#memory-management)
- [Pool Statistics](#pool-statistics)
- [Advanced Usage](#advanced-usage)
- [Configuration](#configuration)
- [Best Practices](#best-practices)
- [Integration with Other Components](#integration-with-other-components)

## Key Features

The `MemoryPool` provides several critical capabilities:

- **Object reuse**: Store and retrieve large objects from memory
- **Automatic pool management**: LRU (Least Recently Used) eviction when pool reaches capacity
- **Memory optimization**: Reduce overhead of repeatedly creating and destroying large objects
- **Configurable capacity**: Control maximum number of objects in pool
- **Access tracking**: Automatically track usage patterns for optimal eviction

## Usage Examples

### Basic Usage

```php
// Create a new memory pool with default capacity
$memoryPool = new \Glueful\Performance\MemoryPool();

// Store an object in the pool
$expensiveObject = createLargeObject();
$memoryPool->add('large-object-key', $expensiveObject);

// Later, retrieve the object instead of recreating it
if ($memoryPool->has('large-object-key')) {
    $object = $memoryPool->get('large-object-key');
} else {
    $object = createLargeObject();
    $memoryPool->add('large-object-key', $object);
}
```

### Custom Pool Capacity

```php
// Create a pool with custom capacity
$memoryPool = new \Glueful\Performance\MemoryPool(50); // Store up to 50 objects

// Add multiple objects
for ($i = 0; $i < 100; $i++) {
    $object = createObjectForId($i);
    $memoryPool->add("object-{$i}", $object);
    
    // The pool will automatically evict least recently used items
    // once it reaches the maximum capacity of 50
}
```

## Object Lifecycle

The `MemoryPool` manages objects throughout their lifecycle:

```php
$memoryPool = new \Glueful\Performance\MemoryPool();

// Adding objects
$memoryPool->add('user-profile', $userProfile);
$memoryPool->add('app-config', $appConfig);

// Retrieving objects
$profile = $memoryPool->get('user-profile');

// Checking for existence
if ($memoryPool->has('app-config')) {
    // Object exists in pool
}

// Removing objects explicitly
$removed = $memoryPool->remove('user-profile');

// Clearing the entire pool
$memoryPool->clear();
```

Each time an object is retrieved, its "last accessed" timestamp is updated for the LRU algorithm.

## Memory Management

The `MemoryPool` uses an LRU (Least Recently Used) algorithm to manage memory efficiently:

```php
$memoryPool = new \Glueful\Performance\MemoryPool(10); // Limit to 10 objects

// When the pool is full and a new object is added, the least recently used
// object will be automatically evicted to make room

// You can check the current pool size
$currentSize = $memoryPool->size();

// And the maximum capacity
$capacity = $memoryPool->capacity();

// Usage percentage
$usagePercent = ($currentSize / $capacity) * 100;
```

## Pool Statistics

The `MemoryPool` provides statistics about its current state:

```php
$memoryPool = new \Glueful\Performance\MemoryPool();

// Add various objects
$memoryPool->add('config', $config);
$memoryPool->add('templates', $templates);
$memoryPool->add('permissions', $permissions);

// Get pool statistics
$stats = $memoryPool->getStats();

print_r($stats);
// Output:
// [
//    'current_size' => 3,
//    'max_size' => 100,
//    'usage_percentage' => 3.0,
//    'keys' => 3
// ]

// Get all keys in the pool
$keys = $memoryPool->getKeys(); // ['config', 'templates', 'permissions']
```

## Advanced Usage

### Using Memory Pool with Database Results

```php
$memoryPool = new \Glueful\Performance\MemoryPool();
$queryCache = new QueryResultCache($memoryPool);

// Function using the memory pool for caching expensive query results
function getUsersWithRoles($db, $memoryPool) {
    $cacheKey = 'users-with-roles';
    
    // Try to get from pool first
    if ($memoryPool->has($cacheKey)) {
        return $memoryPool->get($cacheKey);
    }
    
    // Expensive query
    $result = $db->query("
        SELECT u.*, GROUP_CONCAT(r.name) as roles
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        GROUP BY u.id
    ")->fetchAll();
    
    // Store in pool for future use
    $memoryPool->add($cacheKey, $result);
    
    return $result;
}
```

### Pool Partitioning

```php
// Create separate pools for different object types
$configPool = new \Glueful\Performance\MemoryPool(20);
$templatePool = new \Glueful\Performance\MemoryPool(50);
$userPool = new \Glueful\Performance\MemoryPool(100);

// Use each pool for its specific domain
$configPool->add('app-settings', $appSettings);
$templatePool->add('email-welcome', $welcomeTemplate);
$userPool->add("user-{$userId}", $userObject);

// This approach allows different pool sizes for different types of objects
// and prevents one type from evicting objects of another type
```

## Configuration

Memory pool size is configured in the `config/performance.php` file:

```php
// config/performance.php
return [
    'memory' => [
        // ... other memory settings
        
        'limits' => [
            'object_pool' => env('MEMORY_LIMIT_OBJECT_POOL', 500), // Default pool size
            // ... other limit settings
        ],
    ]
];
```

## Best Practices

For optimal memory pool usage:

1. **Use meaningful keys** that identify objects uniquely
2. **Pool large objects** rather than small ones to maximize benefits
3. **Consider object lifetime** when deciding what to store in the pool
4. **Monitor pool usage** using the getStats() method
5. **Balance pool size** with your application's memory constraints
6. **Group similar objects** in the same pool for better management

Memory pools work best for:

- Expensive-to-create objects that are reused frequently
- Large objects that would cause memory fragmentation if repeatedly created/destroyed
- Shared resources that need controlled access

## Integration with Other Components

The `MemoryPool` integrates well with other performance components:

```php
// Example: Using MemoryPool with MemoryManager for comprehensive memory management
$memoryManager = new \Glueful\Performance\MemoryManager($logger);
$memoryPool = new \Glueful\Performance\MemoryPool();

// Monitor memory usage
$usage = $memoryManager->getCurrentUsage();

// If memory usage is getting high, clear the pool
if ($usage['percentage'] > 0.7) {
    $logger->info('Memory usage high, clearing object pool');
    $memoryPool->clear();
    $memoryManager->forceGarbageCollection();
}
```

```php
// Example: Using MemoryPool with LazyContainer for deferred object creation
$memoryPool = new \Glueful\Performance\MemoryPool();
$lazyContainer = new \Glueful\Performance\LazyContainer();

// Register a factory in lazy container
$lazyContainer->register('expensive-service', function() {
    return new ExpensiveService();
});

// Get or create service and store in pool
function getService($id, $memoryPool, $lazyContainer) {
    if ($memoryPool->has($id)) {
        return $memoryPool->get($id);
    }
    
    $service = $lazyContainer->get($id);
    $memoryPool->add($id, $service);
    
    return $service;
}
```

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Lazy Container](./lazy-container.md), and [Memory Efficient Iterators](./memory-efficient-iterators.md) documentation.*
