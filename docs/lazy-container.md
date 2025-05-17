# Lazy Container

## Overview

The `LazyContainer` class provides an efficient mechanism for lazy-loading objects, delaying their initialization until they are actually needed. This helps reduce memory usage and startup time by only creating resource-intensive objects when required. This tool is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Factory Registration](#factory-registration)
- [Object Lifecycle](#object-lifecycle)
- [Integration with Other Components](#integration-with-other-components)
- [Comparison with Dependency Injection](#comparison-with-dependency-injection)
- [Performance Considerations](#performance-considerations)
- [Best Practices](#best-practices)

## Key Features

The `LazyContainer` provides several critical capabilities:

- **Deferred instantiation**: Only create objects when they are first requested
- **Factory pattern support**: Define how objects should be created
- **Instance caching**: Store and reuse instances after first creation
- **Simple API**: Straightforward register/get interface

## Usage Examples

### Basic Usage

```php
// Create a new lazy container
$container = new \Glueful\Performance\LazyContainer();

// Register a factory for creating an expensive object
$container->register('database', function() {
    echo "Creating database connection...\n";
    return new DatabaseConnection(/* connection parameters */);
});

// Nothing happens yet since we just registered the factory

// Later, when we need the object, it gets created
$db = $container->get('database'); // "Creating database connection..." is echoed now

// Subsequent calls return the same instance without recreating it
$dbAgain = $container->get('database'); // No output, reuses the existing instance
```

### Multiple Registrations

```php
$container = new \Glueful\Performance\LazyContainer();

// Register multiple factories
$container->register('logger', function() {
    return new Logger();
});

$container->register('mailer', function() {
    return new Mailer();
});

$container->register('cache', function() {
    return new CacheSystem();
});

// Each service is created only when requested
$logger = $container->get('logger'); // Logger is created
$cache = $container->get('cache');   // Cache is created
// Mailer remains uninitialized since it wasn't requested
```

## Factory Registration

The factories registered with `LazyContainer` can perform complex initialization:

```php
$container = new \Glueful\Performance\LazyContainer();

// Register a factory with complex initialization logic
$container->register('report-generator', function() {
    // Create the base object
    $generator = new ReportGenerator();
    
    // Configure it
    $generator->setFormat('PDF');
    $generator->setTemplatePath('/path/to/templates');
    $generator->loadFonts();
    $generator->registerHelpers();
    
    // Return the fully configured object
    return $generator;
});

// All this complex initialization happens only when requested
$reportGenerator = $container->get('report-generator');
```

## Object Lifecycle

Objects in the `LazyContainer` follow this lifecycle:

1. **Registration**: A factory closure is registered with a unique identifier
2. **First Request**: When first requested, the factory is executed to create the instance
3. **Storage**: The created instance is stored in the container
4. **Subsequent Requests**: Return the stored instance without executing the factory again

```php
$container = new \Glueful\Performance\LazyContainer();

// Step 1: Registration
$container->register('service', function() {
    return new ExpensiveService();
});

// Step 2: First Request (creates the instance)
$service = $container->get('service');

// Step 3: Storage (happens automatically inside the container)

// Step 4: Subsequent Requests (returns cached instance)
$sameSvc = $container->get('service');

// $service and $sameSvc are the same instance
var_dump($service === $sameSvc); // bool(true)
```

## Integration with Other Components

The `LazyContainer` works well with other performance optimization components:

```php
// Example: Using LazyContainer with MemoryManager
$container = new \Glueful\Performance\LazyContainer();
$memoryManager = new \Glueful\Performance\MemoryManager();

$container->register('large-service', function() use ($memoryManager) {
    // Check memory before creating large object
    $beforeUsage = $memoryManager->getCurrentUsage();
    
    // Create large object
    $service = new LargeService();
    
    // Log memory usage
    $afterUsage = $memoryManager->getCurrentUsage();
    $memoryUsed = $afterUsage['current'] - $beforeUsage['current'];
    
    echo "Service initialization used " . 
         $memoryManager->formatBytes($memoryUsed) . 
         " of memory\n";
    
    return $service;
});
```

```php
// Example: Using LazyContainer with MemoryPool
$container = new \Glueful\Performance\LazyContainer();
$memoryPool = new \Glueful\Performance\MemoryPool();

// Register dynamic factories based on keys
$container->register('data-processor', function() use ($memoryPool) {
    $processor = new DataProcessor();
    
    // Pre-populate with cached objects from memory pool
    if ($memoryPool->has('processor-templates')) {
        $processor->setTemplates($memoryPool->get('processor-templates'));
    }
    
    return $processor;
});
```

## Comparison with Dependency Injection

While `LazyContainer` shares similarities with dependency injection containers, it has a specific focus on performance optimization:

| Feature | LazyContainer | Typical DI Container |
|---------|--------------|---------------------|
| Primary Purpose | Memory optimization | Dependency management |
| Service Resolution | Lazy (on first use) | Varies (can be eager) |
| Auto-Wiring | No | Often supported |
| Configuration | Simple closure | Often complex definitions |
| Scope/Lifetime | Singleton only | Multiple scopes |
| Feature Set | Minimal, focused | Comprehensive |

Use `LazyContainer` when performance and memory usage are primary concerns, and a full DI container would be overkill.

## Performance Considerations

The `LazyContainer` is designed to optimize memory usage and application startup time:

- **Memory Efficiency**: Objects are only created when needed, reducing baseline memory usage
- **Startup Performance**: Application startup is faster as expensive initializations are deferred
- **Trade-offs**: First access to a service may be slower as it handles initialization

```php
// Measuring impact of lazy loading
$startMemory = memory_get_usage();
$container = new \Glueful\Performance\LazyContainer();

// Register 20 expensive services
for ($i = 1; $i <= 20; $i++) {
    $container->register("service-{$i}", function() use ($i) {
        return new ExpensiveService($i);
    });
}

$afterRegisterMemory = memory_get_usage();
echo "Memory after registration: " . ($afterRegisterMemory - $startMemory) . " bytes\n";

// Access just one service
$service5 = $container->get('service-5');

$afterGetMemory = memory_get_usage();
echo "Memory after getting one service: " . ($afterGetMemory - $afterRegisterMemory) . " bytes\n";

// Access all services
for ($i = 1; $i <= 20; $i++) {
    $container->get("service-{$i}");
}

$finalMemory = memory_get_usage();
echo "Memory after getting all services: " . ($finalMemory - $afterGetMemory) . " bytes\n";
```

## Best Practices

For optimal use of the `LazyContainer`:

1. **Register resource-intensive services** that aren't always needed
2. **Use descriptive identifiers** for registered factories
3. **Keep factories focused** on object creation and initialization
4. **Consider dependencies** in your factory closures (use `use` to capture variables)
5. **Combine with other memory optimization tools** for maximum effect

Avoid:
- Using the container as a general-purpose service container (use a proper DI container instead)
- Registering simple objects that don't have significant initialization overhead
- Creating circular dependencies between factories

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Memory Pool](./memory-pool.md), and the full [Performance Monitoring](./performance-monitoring.md) documentation.*
