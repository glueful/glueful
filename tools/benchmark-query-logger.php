<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

namespace Tests;

require_once __DIR__ . '/../bootstrap.php';

use Glueful\Database\QueryLogger;
use Glueful\Logging\LogManager;

/**
 * QueryLogger Performance Benchmark
 *
 * This script benchmarks the performance of the optimized QueryLogger
 * under various configurations to demonstrate the impact of:
 * - Sampling rates
 * - Batching
 * - Caching of table lookups
 *
 * Usage: php benchmark-query-logger.php [iterations]
 * Example: php benchmark-query-logger.php 5000
 */


// Number of queries to run for each test
$iterations = isset($argv[1]) ? (int)$argv[1] : 1000;

echo "QueryLogger Performance Benchmark\n";
echo "===============================\n";
echo "Running benchmarks with $iterations iterations each\n\n";

// Simple benchmarking function
function benchmark(callable $fn, string $name)
{
    $start = microtime(true);
    $result = $fn();
    $end = microtime(true);
    $duration = ($end - $start) * 1000;

    echo sprintf(
        "%s: %.2f ms (%.2f ops/sec)\n",
        $name,
        $duration,
        $result / ($duration / 1000)
    );

    return [
        'name' => $name,
        'duration_ms' => $duration,
        'ops_per_second' => $result / ($duration / 1000),
        'operations' => $result
    ];
}

// Generate test queries
function generateQueries(int $count)
{
    $queries = [];
    $tables = ['users', 'orders', 'products', 'categories', 'payments'];
    $operations = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    for ($i = 0; $i < $count; $i++) {
        $table = $tables[$i % count($tables)];
        $operation = $operations[$i % count($operations)];

        switch ($operation) {
            case 'SELECT':
                $queries[] = [
                    'sql' => "SELECT * FROM $table WHERE id = ?",
                    'params' => [$i],
                    'purpose' => "Fetch $table record"
                ];
                break;
            case 'INSERT':
                $queries[] = [
                    'sql' => "INSERT INTO $table (name, created_at) VALUES (?, NOW())",
                    'params' => ["Test $i"],
                    'purpose' => "Create new $table"
                ];
                break;
            case 'UPDATE':
                $queries[] = [
                    'sql' => "UPDATE $table SET updated_at = NOW() WHERE id = ?",
                    'params' => [$i],
                    'purpose' => "Update $table"
                ];
                break;
            case 'DELETE':
                $queries[] = [
                    'sql' => "DELETE FROM $table WHERE id = ?",
                    'params' => [$i],
                    'purpose' => "Remove $table"
                ];
                break;
        }
    }

    return $queries;
}

// Prepare test data
$queries = generateQueries(100);
$logger = new LogManager('benchmarks');

// Test 1: Baseline (Full audit logging, no optimizations)
$queryLogger1 = new QueryLogger($logger);
$queryLogger1->configure(true, true);
$queryLogger1->configureAuditLogging(true, 1.0, false);

$resultBaseline = benchmark(function () use ($queryLogger1, $queries, $iterations) {
    for ($i = 0; $i < $iterations; $i++) {
        $queryIndex = $i % count($queries);
        $query = $queries[$queryIndex];

        $queryLogger1->logQuery(
            $query['sql'],
            $query['params'],
            microtime(true),
            null,
            true,
            $query['purpose']
        );
    }
    return $iterations;
}, "Baseline (no optimizations)");

// Test 2: With Table Lookup Caching only
$queryLogger2 = new QueryLogger($logger);
$queryLogger2->configure(true, true);
$queryLogger2->configureAuditLogging(true, 1.0, false);

// Prime the cache with some queries first
for ($i = 0; $i < 20; $i++) {
    $queryIndex = $i % count($queries);
    $query = $queries[$queryIndex];

    $queryLogger2->logQuery(
        $query['sql'],
        $query['params'],
        microtime(true),
        null,
        true,
        $query['purpose']
    );
}

$resultCaching = benchmark(function () use ($queryLogger2, $queries, $iterations) {
    for ($i = 0; $i < $iterations; $i++) {
        $queryIndex = $i % count($queries);
        $query = $queries[$queryIndex];

        $queryLogger2->logQuery(
            $query['sql'],
            $query['params'],
            microtime(true),
            null,
            true,
            $query['purpose']
        );
    }
    return $iterations;
}, "With Table Lookup Caching");

// Test 3: With 10% Sampling
$queryLogger3 = new QueryLogger($logger);
$queryLogger3->configure(true, true);
$queryLogger3->configureAuditLogging(true, 0.1, false);

$resultSampling = benchmark(function () use ($queryLogger3, $queries, $iterations) {
    for ($i = 0; $i < $iterations; $i++) {
        $queryIndex = $i % count($queries);
        $query = $queries[$queryIndex];

        $queryLogger3->logQuery(
            $query['sql'],
            $query['params'],
            microtime(true),
            null,
            true,
            $query['purpose']
        );
    }
    return $iterations;
}, "With 10% Sampling");

// Test 4: With Batching (batch size 10)
$queryLogger4 = new QueryLogger($logger);
$queryLogger4->configure(true, true);
$queryLogger4->configureAuditLogging(true, 1.0, true, 10);

$resultBatching = benchmark(function () use ($queryLogger4, $queries, $iterations) {
    for ($i = 0; $i < $iterations; $i++) {
        $queryIndex = $i % count($queries);
        $query = $queries[$queryIndex];

        $queryLogger4->logQuery(
            $query['sql'],
            $query['params'],
            microtime(true),
            null,
            true,
            $query['purpose']
        );
    }

    // Make sure to flush any remaining batch
    $queryLogger4->flushAuditLogBatch();

    return $iterations;
}, "With Batching (batch size 10)");

// Test 5: With All Optimizations (10% Sampling + Batching + Caching)
$queryLogger5 = new QueryLogger($logger);
$queryLogger5->configure(true, true);
$queryLogger5->configureAuditLogging(true, 0.1, true, 10);

// Prime the cache with some queries first
for ($i = 0; $i < 20; $i++) {
    $queryIndex = $i % count($queries);
    $query = $queries[$queryIndex];

    $queryLogger5->logQuery(
        $query['sql'],
        $query['params'],
        microtime(true),
        null,
        true,
        $query['purpose']
    );
}

$resultAllOptimizations = benchmark(function () use ($queryLogger5, $queries, $iterations) {
    for ($i = 0; $i < $iterations; $i++) {
        $queryIndex = $i % count($queries);
        $query = $queries[$queryIndex];

        $queryLogger5->logQuery(
            $query['sql'],
            $query['params'],
            microtime(true),
            null,
            true,
            $query['purpose']
        );
    }

    // Make sure to flush any remaining batch
    $queryLogger5->flushAuditLogBatch();

    return $iterations;
}, "With All Optimizations");

// Calculate and display improvements
echo "\nPerformance Improvements\n";
echo "=====================\n";

function calculateImprovement($baseline, $optimized)
{
    $percentImprovement = (($optimized['ops_per_second'] / $baseline['ops_per_second']) - 1) * 100;
    return sprintf("%.2f%% faster", $percentImprovement);
}

echo "Caching only: " . calculateImprovement($resultBaseline, $resultCaching) . "\n";
echo "Sampling only: " . calculateImprovement($resultBaseline, $resultSampling) . "\n";
echo "Batching only: " . calculateImprovement($resultBaseline, $resultBatching) . "\n";
echo "All optimizations: " . calculateImprovement($resultBaseline, $resultAllOptimizations) . "\n";

echo "\nNote: Actual performance gains in production environments may be significantly higher\n";
echo "depending on the complexity of audit logging and database operations.\n";
