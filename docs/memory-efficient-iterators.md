# Memory-Efficient Iterators

## Overview

The Memory-Efficient Iterators in Glueful provide optimized tools for processing large datasets with minimal memory footprint. These iterators help prevent memory exhaustion and improve performance when working with large collections of data. This feature is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Available Iterators](#available-iterators)
- [Usage Examples](#usage-examples)
- [StreamingIterator](#streamingiterator)
- [Chunked Processing](#chunked-processing)
- [Database Result Processing](#database-result-processing)
- [Iterator Composition](#iterator-composition)
- [Memory Usage Comparison](#memory-usage-comparison)
- [Best Practices](#best-practices)
- [Integration with Other Components](#integration-with-other-components)

## Key Features

The Memory-Efficient Iterators provide several critical capabilities:

- **Low memory overhead**: Process large datasets without loading everything into memory
- **Streaming processing**: Work with data as it becomes available
- **Chunked operations**: Process data in manageable batches
- **Functional approach**: Apply map, filter, and reduce operations efficiently
- **Database integration**: Stream database results without buffering the entire result set

## Available Iterators

Glueful provides several specialized iterator types:

- **StreamingIterator**: Base iterator for memory-efficient sequential access
- **ChunkedDatabaseProcessor**: Process database query results in manageable chunks
- **MemoryEfficientIterators**: Utility class with static methods for common iterator operations

## Usage Examples

### Basic Usage

```php
// Create a streaming iterator from an array
$data = range(1, 1000000); // Large array that would normally use a lot of memory
$iterator = new \Glueful\Performance\StreamingIterator($data);

// Process each item with low memory overhead
foreach ($iterator as $key => $value) {
    // Process item
    // Only one item is in memory at a time
}
```

### Using Map, Filter, Reduce Operations

```php
$iterator = new \Glueful\Performance\StreamingIterator($largeDataset);

// Map operation - transform each item
$doubled = $iterator->map(function($value) {
    return $value * 2;
});

// Filter operation - keep only items matching a condition
$filtered = $doubled->filter(function($value) {
    return $value > 100;
});

// Reduce operation - aggregate results
$sum = $filtered->reduce(function($carry, $value) {
    return $carry + $value;
}, 0);

// Chain operations
$result = $iterator
    ->map(fn($v) => $v * 2)
    ->filter(fn($v) => $v > 100)
    ->reduce(fn($carry, $v) => $carry + $v, 0);
```

## StreamingIterator

The `StreamingIterator` is the foundation of Glueful's memory-efficient iteration:

```php
// Create from various sources

// From an array
$arrayIterator = new StreamingIterator([1, 2, 3, 4, 5]);

// From another iterator
$generator = function() {
    for ($i = 0; $i < 10; $i++) {
        yield $i => "Value $i";
    }
};
$generatorIterator = new StreamingIterator($generator());

// From a database result
$stmt = $pdo->query("SELECT * FROM large_table");
$dbIterator = new StreamingIterator($stmt);

// Configure buffer size for internal operations
$optimizedIterator = new StreamingIterator($largeDataset, 50); // Buffer 50 items
```

### Streaming Iterator Operations

The `StreamingIterator` provides several methods for working with data streams:

```php
$iterator = new StreamingIterator($data);

// Transform data with map
$transformed = $iterator->map(function($value, $key) {
    return [
        'id' => $key,
        'value' => $value,
        'processed' => true
    ];
});

// Filter data
$filtered = $iterator->filter(function($value, $key) {
    return $value > 1000;
});

// Reduce to a single value
$sum = $iterator->reduce(function($carry, $value) {
    return $carry + $value;
}, 0);

// Get the first N items
$first10 = $iterator->take(10);

// Skip the first N items
$withoutFirst20 = $iterator->skip(20);

// Combine multiple operations
$result = $iterator
    ->skip(10)
    ->take(100)
    ->map(fn($v) => $v * 2)
    ->filter(fn($v) => $v % 2 === 0);
```

## Chunked Processing

The `ChunkedDatabaseProcessor` and `MemoryEfficientIterators` provide tools for processing data in chunks:

```php
// Process database results in chunks
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($pdo);

$results = $processor->processSelectQuery(
    "SELECT * FROM huge_table WHERE status = ?",
    function($chunk) {
        // Process a chunk of results (e.g., 1000 rows at a time)
        foreach ($chunk as $row) {
            // Process each row
        }
        return true; // Continue processing
    },
    ['active'],
    1000 // Chunk size
);
```

```php
// Using the static helpers for chunked processing
$results = \Glueful\Performance\MemoryEfficientIterators::processInChunks(
    $largeArray,
    function($chunk) {
        // Process each chunk
        $transformedChunk = [];
        foreach ($chunk as $item) {
            $transformedChunk[] = transformItem($item);
        }
        return $transformedChunk;
    },
    500 // Chunk size
);
```

## Database Result Processing

The memory-efficient iterators are particularly useful for database operations:

```php
// Using PDO
$stmt = $pdo->prepare("SELECT * FROM million_row_table");
$stmt->execute();

// Create a streaming iterator for the results
$iterator = new \Glueful\Performance\StreamingIterator(
    \Glueful\Performance\MemoryEfficientIterators::databaseResults($stmt)
);

// Process each row without loading the entire result set into memory
foreach ($iterator as $row) {
    processRow($row);
}
```

```php
// For specialized database processing with different drivers
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($connection);

// Process results from different database drivers
if ($connection instanceof \PDO) {
    // Use PDO-specific processing
    $results = $processor->processPdoQuery($query, $processorFunction);
} elseif ($connection instanceof \mysqli) {
    // Use mysqli-specific processing
    $results = $processor->processMysqliQuery($query, $processorFunction);
} elseif (method_exists($connection, 'cursor')) {
    // Use Laravel DB-specific processing
    $results = $processor->processLaravelQuery($query, $processorFunction);
}
```

## Iterator Composition

Memory-efficient iterators can be composed for complex data processing:

```php
// Complex data processing pipeline
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($pdo);
$memoryManager = new \Glueful\Performance\MemoryManager();

// Process users in chunks, generate reports, and aggregate statistics
$statistics = $processor->processSelectQuery(
    "SELECT * FROM users WHERE created_at > ?",
    function($chunk) use ($memoryManager) {
        // Monitor memory during processing
        $memoryBefore = $memoryManager->getCurrentUsage();
        
        // Process this chunk of users
        $processedData = [];
        foreach ($chunk as $user) {
            $processedData[] = generateUserReport($user);
        }
        
        // Check memory after processing chunk
        $memoryAfter = $memoryManager->getCurrentUsage();
        $memoryUsed = $memoryAfter['current'] - $memoryBefore['current'];
        
        // Force garbage collection if memory usage is high
        if ($memoryAfter['percentage'] > 0.7) {
            $memoryManager->forceGarbageCollection();
        }
        
        return $processedData;
    },
    [date('Y-m-d', strtotime('-30 days'))],
    500 // Process 500 users at a time
);
```

## Memory Usage Comparison

The following example demonstrates the memory efficiency of these iterators compared to standard array operations:

```php
// Using regular array operations (high memory usage)
function processDataStandard($data) {
    $mapped = array_map(function($item) {
        return processItem($item);
    }, $data);
    
    $filtered = array_filter($mapped, function($item) {
        return $item['score'] > 50;
    });
    
    return array_reduce($filtered, function($carry, $item) {
        return $carry + $item['value'];
    }, 0);
}

// Using memory-efficient iterators (low memory usage)
function processDataEfficient($data) {
    $iterator = new \Glueful\Performance\StreamingIterator($data);
    
    return $iterator
        ->map(function($item) {
            return processItem($item);
        })
        ->filter(function($item) {
            return $item['score'] > 50;
        })
        ->reduce(function($carry, $item) {
            return $carry + $item['value'];
        }, 0);
}

// Memory usage comparison
$largeDataset = getLargeDataset(); // Millions of items

$startMemory1 = memory_get_usage();
$result1 = processDataStandard($largeDataset);
$endMemory1 = memory_get_usage();
echo "Standard approach memory: " . ($endMemory1 - $startMemory1) . " bytes\n";

$startMemory2 = memory_get_usage();
$result2 = processDataEfficient($largeDataset);
$endMemory2 = memory_get_usage();
echo "Efficient approach memory: " . ($endMemory2 - $startMemory2) . " bytes\n";
```

## Best Practices

For optimal use of memory-efficient iterators:

1. **Use streaming iterators** for large datasets that would otherwise consume too much memory
2. **Process data in chunks** for better memory control and potential parallelization
3. **Combine with memory monitoring** to track memory usage during processing
4. **Use functional-style operations** (map, filter, reduce) for clean, memory-efficient code
5. **Consider buffer size** when initializing iterators for optimal performance
6. **Release references** to large objects after processing to help garbage collection

```php
// Example of proper resource release
function processLargeFile($filePath) {
    $handle = fopen($filePath, 'r');
    $iterator = new \Glueful\Performance\StreamingIterator(
        \Glueful\Performance\MemoryEfficientIterators::fileLines($handle)
    );
    
    $result = $iterator
        ->filter(fn($line) => !empty(trim($line)))
        ->map(fn($line) => parseLine($line))
        ->reduce(fn($carry, $item) => $carry + $item['value'], 0);
    
    // Properly close resources
    fclose($handle);
    
    // Help garbage collection by removing references
    $iterator = null;
    
    return $result;
}
```

## Integration with Other Components

The memory-efficient iterators integrate well with other performance components:

```php
// Example: Using iterators with MemoryManager and MemoryPool
$memoryManager = new \Glueful\Performance\MemoryManager();
$memoryPool = new \Glueful\Performance\MemoryPool();
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($pdo);

// Process large dataset with comprehensive memory management
$result = $processor->processSelectQuery(
    "SELECT * FROM analytics_data WHERE date BETWEEN ? AND ?",
    function($chunk) use ($memoryManager, $memoryPool) {
        // Check memory status
        $memoryStatus = $memoryManager->monitor();
        
        // Process chunk
        $transformedData = transformData($chunk);
        
        // Store intermediate results in memory pool if needed
        $key = 'analytics-chunk-' . md5(serialize(array_keys($chunk)));
        $memoryPool->add($key, $transformedData);
        
        // Force garbage collection if memory is getting high
        if ($memoryStatus['percentage'] > 0.7) {
            $memoryManager->forceGarbageCollection();
        }
        
        return $transformedData;
    },
    [$startDate, $endDate],
    1000
);
```

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Memory Pool](./memory-pool.md), and [ChunkedDatabaseProcessor](./chunked-database-processor.md) documentation.*
