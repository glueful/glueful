# Chunked Database Processor

## Overview

The `ChunkedDatabaseProcessor` provides memory-efficient processing of large database query results, breaking the results into manageable chunks to prevent memory exhaustion. This tool is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Supported Database Connections](#supported-database-connections)
- [Processing Database Results](#processing-database-results)
- [Chunking Strategy](#chunking-strategy)
- [Integration with Other Components](#integration-with-other-components)
- [Error Handling](#error-handling)
- [Performance Considerations](#performance-considerations)
- [Advanced Usage](#advanced-usage)
- [Best Practices](#best-practices)

## Key Features

The `ChunkedDatabaseProcessor` provides several critical capabilities:

- **Memory-efficient processing**: Work with large result sets without memory exhaustion
- **Chunk-based iteration**: Process query results in manageable batches
- **Multi-driver support**: Compatible with PDO, mysqli, and Laravel database connections
- **Processor callbacks**: Apply custom processing logic to each chunk
- **Configurable chunk size**: Adjust based on memory constraints and processing needs

## Usage Examples

### Basic Usage

```php
// Create a processor with a PDO connection
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'username', 'password');
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($pdo);

// Process a large result set in chunks
$results = $processor->processSelectQuery(
    "SELECT * FROM large_table WHERE status = ?",
    function($chunk) {
        // Process each chunk (e.g., 1000 rows at a time)
        $processedData = [];
        foreach ($chunk as $row) {
            $processedData[] = transformRow($row);
        }
        return $processedData; // Return processed chunk
    },
    ['active'], // Query parameters
    1000 // Chunk size (1000 rows per chunk)
);

// $results now contains processed data from all chunks
```

### With Different Database Connections

```php
// Using mysqli
$mysqli = new \mysqli('localhost', 'username', 'password', 'myapp');
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($mysqli);

// Using Laravel DB connection
$db = DB::connection();
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($db);
```

## Supported Database Connections

The `ChunkedDatabaseProcessor` supports multiple database connection types:

```php
// PDO connection
if ($connection instanceof \PDO) {
    return $this->processPdoQuery($query, $processor, $params, $chunkSize);
}

// mysqli connection
elseif ($connection instanceof \mysqli) {
    return $this->processMysqliQuery($query, $processor, $params, $chunkSize);
}

// Laravel DB connection (detected by 'cursor' method)
elseif (method_exists($connection, 'cursor')) {
    return $this->processLaravelQuery($query, $processor, $params, $chunkSize);
}
```

Each connection type has specialized processing methods to utilize the most efficient approach for that driver.

## Processing Database Results

Processing happens in three main stages:

### 1. Query Preparation and Execution

```php
// For PDO
$stmt = $this->connection->prepare($query);
$stmt->execute($params);

// For mysqli
$stmt = $this->connection->prepare($query);
// Bind parameters
$stmt->execute();

// For Laravel
$results = $this->connection->cursor($query, $params);
```

### 2. Result Streaming and Chunking

```php
// Create an efficient iterator for database results
$iterator = MemoryEfficientIterators::databaseResults($stmt);

// Process in chunks
return MemoryEfficientIterators::processInChunks(
    $iterator,
    $processor,
    $chunkSize
);
```

### 3. Chunk Processing

```php
// Example processor function
function processChunk($chunk) {
    $results = [];
    foreach ($chunk as $row) {
        // Transform row data
        $results[] = [
            'id' => $row['id'],
            'name' => strtoupper($row['name']),
            'processed' => true,
            'timestamp' => time()
        ];
    }
    return $results;
}
```

## Chunking Strategy

The chunking strategy optimizes memory usage by:

1. **Fetching results incrementally** rather than loading the entire result set
2. **Processing in batches** of a configurable size
3. **Freeing memory** after each chunk is processed
4. **Aggregating results** from each chunk as needed

```php
// Chunking implementation in MemoryEfficientIterators
function processInChunks($iterator, $processor, $chunkSize) {
    $results = [];
    $chunk = [];
    $count = 0;
    
    foreach ($iterator as $item) {
        $chunk[] = $item;
        $count++;
        
        // When chunk reaches desired size, process it
        if ($count >= $chunkSize) {
            $chunkResult = $processor($chunk);
            if ($chunkResult !== null) {
                if (is_array($chunkResult)) {
                    $results = array_merge($results, $chunkResult);
                } else {
                    $results[] = $chunkResult;
                }
            }
            
            // Reset for next chunk
            $chunk = [];
            $count = 0;
        }
    }
    
    // Process any remaining items
    if ($count > 0) {
        $chunkResult = $processor($chunk);
        if ($chunkResult !== null) {
            if (is_array($chunkResult)) {
                $results = array_merge($results, $chunkResult);
            } else {
                $results[] = $chunkResult;
            }
        }
    }
    
    return $results;
}
```

## Integration with Other Components

The `ChunkedDatabaseProcessor` integrates well with other performance components:

```php
// Using with MemoryManager for monitoring
$memoryManager = new \Glueful\Performance\MemoryManager($logger);
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($pdo);

$results = $processor->processSelectQuery(
    "SELECT * FROM analytics_data WHERE date BETWEEN ? AND ?",
    function($chunk) use ($memoryManager) {
        // Monitor memory before processing
        $beforeUsage = $memoryManager->getCurrentUsage();
        
        // Process chunk
        $processedChunk = processAnalyticsData($chunk);
        
        // Monitor after processing
        $afterUsage = $memoryManager->getCurrentUsage();
        $memoryChange = $afterUsage['current'] - $beforeUsage['current'];
        
        // Log memory usage
        $logger->info("Processed " . count($chunk) . " rows using " . 
                     $memoryManager->formatBytes($memoryChange) . " memory");
        
        // Force garbage collection if needed
        if ($afterUsage['percentage'] > 0.7) {
            $memoryManager->forceGarbageCollection();
        }
        
        return $processedChunk;
    },
    [$startDate, $endDate],
    500 // Process 500 rows at a time
);
```

## Error Handling

The `ChunkedDatabaseProcessor` includes robust error handling:

```php
try {
    $stmt = $this->connection->prepare($query);
    $stmt->execute($params);
    
    return MemoryEfficientIterators::processInChunks(
        MemoryEfficientIterators::databaseResults($stmt),
        $processor,
        $chunkSize
    );
} catch (\PDOException $e) {
    // Handle database errors
    $this->logger->error("Database error during chunked processing", [
        'query' => $query,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
    throw $e;
} catch (\Exception $e) {
    // Handle processor errors
    $this->logger->error("Error during chunk processing", [
        'error' => $e->getMessage()
    ]);
    
    throw $e;
}
```

## Performance Considerations

When using the `ChunkedDatabaseProcessor`, consider these performance factors:

1. **Chunk Size**: Larger chunks require more memory but involve less overhead
   ```php
   // Smaller chunks (less memory, more overhead)
   $processor->processSelectQuery($query, $callback, $params, 100);
   
   // Larger chunks (more memory, less overhead)
   $processor->processSelectQuery($query, $callback, $params, 5000);
   ```

2. **Database Driver**: Different drivers have different performance characteristics
   ```php
   // PDO generally has the best memory efficiency
   $pdoProcessor = new ChunkedDatabaseProcessor($pdo);
   
   // mysqli can be faster for certain operations
   $mysqliProcessor = new ChunkedDatabaseProcessor($mysqli);
   
   // Laravel DB adds convenience but some overhead
   $laravelProcessor = new ChunkedDatabaseProcessor($db);
   ```

3. **Query Complexity**: Simpler queries allow more efficient chunking
   ```php
   // Simple queries work well with chunking
   "SELECT * FROM users WHERE status = ?"
   
   // Complex queries may use more resources per chunk
   "SELECT u.*, COUNT(o.id) as order_count 
    FROM users u 
    LEFT JOIN orders o ON u.id = o.user_id 
    WHERE u.status = ? 
    GROUP BY u.id"
   ```

## Advanced Usage

### Custom Iterator Sources

```php
// Custom iterator source for specialized databases
class CustomDatabaseResults implements \Iterator {
    // Iterator implementation
}

// Usage with ChunkedDatabaseProcessor
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($connection);
$stmt = $connection->query($query);
$iterator = new CustomDatabaseResults($stmt);

$results = MemoryEfficientIterators::processInChunks(
    $iterator,
    $processorFunction,
    $chunkSize
);
```

### Data Export Use Case

```php
// Exporting large dataset to CSV with minimal memory usage
function exportLargeTableToCsv($tableName, $outputFile, $connection) {
    $processor = new \Glueful\Performance\ChunkedDatabaseProcessor($connection);
    $csvHandle = fopen($outputFile, 'w');
    
    // Write CSV header
    fputcsv($csvHandle, ['id', 'name', 'email', 'created_at']);
    
    // Process in chunks, writing directly to CSV
    $processor->processSelectQuery(
        "SELECT id, name, email, created_at FROM {$tableName}",
        function($chunk) use ($csvHandle) {
            foreach ($chunk as $row) {
                fputcsv($csvHandle, $row);
            }
            // No need to return data since we're writing directly to file
            return null;
        },
        [], // No parameters
        1000 // 1000 rows per chunk
    );
    
    fclose($csvHandle);
    return true;
}
```

### ETL Processing

```php
// Extract-Transform-Load pattern with chunked processing
function etlProcess($sourceConnection, $targetConnection) {
    $processor = new \Glueful\Performance\ChunkedDatabaseProcessor($sourceConnection);
    $targetPdo = $targetConnection->getPdo();
    
    // Prepare insert statement
    $insertStmt = $targetPdo->prepare(
        "INSERT INTO target_table (id, processed_data) VALUES (?, ?)"
    );
    
    // Begin transaction for efficiency
    $targetPdo->beginTransaction();
    $rowsProcessed = 0;
    
    try {
        // Process in chunks with automatic commits
        $processor->processSelectQuery(
            "SELECT id, raw_data FROM source_table WHERE processed = 0",
            function($chunk) use ($insertStmt, $targetPdo, &$rowsProcessed) {
                foreach ($chunk as $row) {
                    // Transform data
                    $processedData = transformData($row['raw_data']);
                    
                    // Load into target
                    $insertStmt->execute([$row['id'], $processedData]);
                    $rowsProcessed++;
                }
                
                // Commit every chunk
                $targetPdo->commit();
                $targetPdo->beginTransaction();
                
                return $rowsProcessed;
            },
            [],
            500
        );
        
        // Final commit
        $targetPdo->commit();
        return $rowsProcessed;
    } catch (\Exception $e) {
        $targetPdo->rollBack();
        throw $e;
    }
}
```

## Best Practices

For optimal use of the chunked database processor:

1. **Choose appropriate chunk sizes** based on row size and memory constraints
2. **Monitor memory usage** during processing to fine-tune chunk size
3. **Use prepared statements** to avoid SQL injection vulnerabilities
4. **Process data incrementally** rather than building large result arrays
5. **Consider transaction boundaries** when making database changes
6. **Keep processor callbacks focused** on data transformation
7. **Set appropriate PDO fetch modes** for optimal memory usage

```php
// Example of optimal settings
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$processor = new \Glueful\Performance\ChunkedDatabaseProcessor($pdo);

// Determine optimal chunk size
$rowSize = getAverageRowSize('large_table');
$memoryLimit = $memoryManager->getMemoryLimit() * 0.1; // Use 10% of memory limit
$optimalChunkSize = max(100, min(10000, floor($memoryLimit / $rowSize)));

// Process with optimal chunk size
$results = $processor->processSelectQuery(
    $query,
    $processorCallback,
    $params,
    $optimalChunkSize
);
```

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Memory-Efficient Iterators](./memory-efficient-iterators.md), and [Query Optimization](./query-optimizer.md) documentation.*
