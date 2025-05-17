<?php

namespace Glueful\Performance;

class MemoryEfficientIterators
{
    /**
     * Create a streaming iterator for large datasets
     *
     * This method allows processing of large datasets without loading everything into memory
     *
     * @param \Iterator|\Generator|array $dataSource The data source to iterate over
     * @param int $bufferSize The number of items to buffer at a time (default: 100)
     * @return \Generator A generator that yields items from the data source
     */
    public static function stream(\Iterator|\Generator|array $dataSource, int $bufferSize = 100): \Generator
    {
        if (is_array($dataSource)) {
            foreach ($dataSource as $key => $value) {
                yield $key => $value;
            }
            return;
        }

        // At this point $dataSource can only be an Iterator or Generator due to type declaration
        foreach ($dataSource as $key => $value) {
            yield $key => $value;
        }
    }

    /**
     * Process a large dataset in chunks to reduce memory usage
     *
     * @param \Iterator|\Generator|array $dataSource The data source to chunk
     * @param callable $callback The callback to apply to each chunk
     * @param int $chunkSize The size of each chunk (default: 1000)
     * @param bool $preserveKeys Whether to preserve array keys (default: true)
     * @return array An array of results from the callback
     */
    public static function processInChunks(
        $dataSource,
        callable $callback,
        int $chunkSize = 1000,
        bool $preserveKeys = true
    ): array {
        $results = [];
        $currentChunk = [];
        $currentSize = 0;

        foreach (self::stream($dataSource) as $key => $item) {
            if ($preserveKeys) {
                $currentChunk[$key] = $item;
            } else {
                $currentChunk[] = $item;
            }

            $currentSize++;

            if ($currentSize >= $chunkSize) {
                $results[] = $callback($currentChunk);
                $currentChunk = [];
                $currentSize = 0;
            }
        }

        // Process the remaining items
        if ($currentSize > 0) {
            $results[] = $callback($currentChunk);
        }

        return $results;
    }

    /**
     * Process chunks in parallel using PHP's parallel extension if available
     * Falls back to sequential processing if parallel extension is not available
     *
     * @param \Iterator|\Generator|array $dataSource The data source to chunk
     * @param callable $callback The callback to apply to each chunk
     * @param int $chunkSize The size of each chunk (default: 1000)
     * @param int $maxParallel Maximum number of parallel processes (default: 4)
     * @return array An array of results from the callback
     */
    public static function processInParallelChunks(
        $dataSource,
        callable $callback,
        int $chunkSize = 1000,
        int $maxParallel = 4
    ): array {
        // Check if parallel extension is available
        if (extension_loaded('parallel')) {
            // Implementation using parallel extension would go here
            // This is a placeholder for actual parallel processing
            return self::processInChunks($dataSource, $callback, $chunkSize);
        }

        // Fall back to sequential processing
        return self::processInChunks($dataSource, $callback, $chunkSize);
    }

    /**
     * Create a generator for database query results
     *
     * This method allows memory-efficient processing of large database result sets
     *
     * @param \PDOStatement|\mysqli_stmt $statement The prepared statement to fetch from
     * @param int $fetchSize The number of rows to fetch at a time (default: 100)
     * @return \Generator A generator that yields rows from the result set
     */
    public static function databaseResults($statement, int $fetchSize = 100): \Generator
    {
        if ($statement instanceof \PDOStatement) {
            // PDO implementation
            while ($rows = $statement->fetch(\PDO::FETCH_ASSOC)) {
                yield $rows;
            }
        } elseif ($statement instanceof \mysqli_stmt) {
            // MySQLi implementation
            $result = $statement->get_result();
            while ($row = $result->fetch_assoc()) {
                yield $row;
            }
        } else {
            throw new \InvalidArgumentException('Statement must be a PDOStatement or mysqli_stmt');
        }
    }

    /**
     * Process a large file line by line without loading the entire file into memory
     *
     * @param string $filePath Path to the file
     * @param callable|null $lineProcessor Optional callback to process each line
     * @return \Generator A generator that yields each line from the file
     */
    public static function fileLineByLine(string $filePath, ?callable $lineProcessor = null): \Generator
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open file: {$filePath}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if ($lineProcessor !== null) {
                    $line = $lineProcessor($line);
                }
                yield $line;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Map a function over each item in an iterator without loading everything into memory
     *
     * @param \Iterator|\Generator|array $dataSource The data source to iterate over
     * @param callable $mapFunction The function to apply to each item
     * @return \Generator A generator that yields the mapped items
     */
    public static function map($dataSource, callable $mapFunction): \Generator
    {
        foreach (self::stream($dataSource) as $key => $value) {
            yield $key => $mapFunction($value, $key);
        }
    }

    /**
     * Filter items from an iterator without loading everything into memory
     *
     * @param \Iterator|\Generator|array $dataSource The data source to iterate over
     * @param callable $filterFunction The function to determine which items to keep
     * @return \Generator A generator that yields the filtered items
     */
    public static function filter($dataSource, callable $filterFunction): \Generator
    {
        foreach (self::stream($dataSource) as $key => $value) {
            if ($filterFunction($value, $key)) {
                yield $key => $value;
            }
        }
    }
}
