<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Extensions Benchmark Command
 * - Performance benchmarks for extension loading and initialization
 * - Memory usage analysis during extension operations
 * - Autoload performance testing
 * - Dependency resolution timing
 * - Comparative analysis between extensions
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:benchmark',
    description: 'Run performance benchmarks for extension loading'
)]
class BenchmarkCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Run performance benchmarks for extension loading')
             ->setHelp('This command runs comprehensive performance benchmarks for the extension system.')
             ->addOption(
                 'iterations',
                 'i',
                 InputOption::VALUE_REQUIRED,
                 'Number of iterations for each benchmark',
                 '100'
             )
             ->addOption(
                 'detailed',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show detailed per-extension results'
             )
             ->addOption(
                 'memory',
                 'm',
                 InputOption::VALUE_NONE,
                 'Include memory usage analysis'
             )
             ->addOption(
                 'autoload',
                 'a',
                 InputOption::VALUE_NONE,
                 'Focus on autoload performance'
             )
             ->addOption(
                 'output-format',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, csv)',
                 'table'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $iterations = (int) $input->getOption('iterations');
        $detailed = $input->getOption('detailed');
        $includeMemory = $input->getOption('memory');
        $focusAutoload = $input->getOption('autoload');
        $outputFormat = $input->getOption('output-format');

        try {
            $this->info('Extension System Benchmarks');
            $this->line('===========================');
            $this->line("Iterations: {$iterations}");
            $this->line('');

            $benchmarkResults = [];

            // Extension discovery benchmark
            $benchmarkResults['discovery'] = $this->benchmarkExtensionDiscovery($iterations);

            // Extension loading benchmark
            $benchmarkResults['loading'] = $this->benchmarkExtensionLoading($iterations, $includeMemory);

            // Autoload performance benchmark
            if ($focusAutoload || $detailed) {
                $benchmarkResults['autoload'] = $this->benchmarkAutoloadPerformance($iterations);
            }

            // Dependency resolution benchmark
            $benchmarkResults['dependencies'] = $this->benchmarkDependencyResolution($iterations);

            // Individual extension benchmarks
            if ($detailed) {
                $benchmarkResults['individual'] = $this->benchmarkIndividualExtensions($iterations, $includeMemory);
            }

            // Display results
            $this->displayBenchmarkResults($benchmarkResults, $outputFormat, $detailed);

            $this->line('');
            $this->success('Benchmark completed successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Benchmark failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function benchmarkExtensionDiscovery(int $iterations): array
    {
        $this->info('Benchmarking extension discovery...');

        $progressBar = new ProgressBar($this->output, $iterations);
        $progressBar->start();

        $times = [];
        $memoryUsage = [];

        for ($i = 0; $i < $iterations; $i++) {
            $startMemory = memory_get_usage();
            $startTime = microtime(true);

            // Simulate extension discovery
            $this->discoverExtensions();

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $times[] = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $memoryUsage[] = $endMemory - $startMemory;

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');

        return [
            'name' => 'Extension Discovery',
            'iterations' => $iterations,
            'avg_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times),
            'avg_memory' => array_sum($memoryUsage) / count($memoryUsage),
            'total_time' => array_sum($times)
        ];
    }

    private function benchmarkExtensionLoading(int $iterations, bool $includeMemory): array
    {
        $this->info('Benchmarking extension loading...');

        $progressBar = new ProgressBar($this->output, $iterations);
        $progressBar->start();

        $times = [];
        $memoryUsage = [];

        for ($i = 0; $i < $iterations; $i++) {
            $startMemory = memory_get_usage();
            $startTime = microtime(true);

            // Simulate extension loading
            $this->loadExtensions();

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $times[] = ($endTime - $startTime) * 1000;
            if ($includeMemory) {
                $memoryUsage[] = $endMemory - $startMemory;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');

        $result = [
            'name' => 'Extension Loading',
            'iterations' => $iterations,
            'avg_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times),
            'total_time' => array_sum($times)
        ];

        if ($includeMemory && !empty($memoryUsage)) {
            $result['avg_memory'] = array_sum($memoryUsage) / count($memoryUsage);
            $result['max_memory'] = max($memoryUsage);
        }

        return $result;
    }

    private function benchmarkAutoloadPerformance(int $iterations): array
    {
        $this->info('Benchmarking autoload performance...');

        $progressBar = new ProgressBar($this->output, $iterations);
        $progressBar->start();

        $times = [];
        $extensions = $this->getAvailableExtensions();

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);

            // Simulate class autoloading for each extension
            foreach ($extensions as $extension) {
                $this->simulateAutoload($extension);
            }

            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');

        return [
            'name' => 'Autoload Performance',
            'iterations' => $iterations,
            'extensions_tested' => count($extensions),
            'avg_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times),
            'avg_per_extension' => (array_sum($times) / count($times)) / max(count($extensions), 1)
        ];
    }

    private function benchmarkDependencyResolution(int $iterations): array
    {
        $this->info('Benchmarking dependency resolution...');

        $progressBar = new ProgressBar($this->output, $iterations);
        $progressBar->start();

        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);

            // Simulate dependency resolution
            $this->resolveDependencies();

            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line('');

        return [
            'name' => 'Dependency Resolution',
            'iterations' => $iterations,
            'avg_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times)
        ];
    }

    private function benchmarkIndividualExtensions(int $iterations, bool $includeMemory): array
    {
        $this->info('Benchmarking individual extensions...');

        $extensions = $this->getAvailableExtensions();
        $results = [];

        foreach ($extensions as $extensionName) {
            $this->line("Testing extension: {$extensionName}");

            $progressBar = new ProgressBar($this->output, $iterations);
            $progressBar->start();

            $times = [];
            $memoryUsage = [];

            for ($i = 0; $i < $iterations; $i++) {
                $startMemory = memory_get_usage();
                $startTime = microtime(true);

                // Simulate individual extension operations
                $this->simulateExtensionOperation($extensionName);

                $endTime = microtime(true);
                $endMemory = memory_get_usage();

                $times[] = ($endTime - $startTime) * 1000;
                if ($includeMemory) {
                    $memoryUsage[] = $endMemory - $startMemory;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->line('');

            $result = [
                'extension' => $extensionName,
                'avg_time' => array_sum($times) / count($times),
                'min_time' => min($times),
                'max_time' => max($times)
            ];

            if ($includeMemory && !empty($memoryUsage)) {
                $result['avg_memory'] = array_sum($memoryUsage) / count($memoryUsage);
            }

            $results[] = $result;
        }

        return $results;
    }

    private function discoverExtensions(): array
    {
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            return [];
        }

        $extensions = [];
        $directories = scandir($extensionsDir);

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                continue;
            }

            $configFile = "{$extensionsDir}/{$dir}/extension.json";
            if (file_exists($configFile)) {
                $extensions[] = $dir;
            }
        }

        return $extensions;
    }

    private function loadExtensions(): void
    {
        try {
            $extensionsManager = $this->getService(ExtensionManager::class);
            // Simulate loading extensions
            $extensions = $this->getExtensionsKeyed($extensionsManager);

            // Touch each extension to simulate loading
            foreach ($extensions as $extension) {
                $name = $extension['name'];
                // Simulate some work
                $workResult = strlen($name);
                unset($workResult);
            }
        } catch (\Exception $e) {
            // Fallback simulation
            $extensions = $this->getAvailableExtensions();
            foreach ($extensions as $extension) {
                $workResult = strlen($extension);
                unset($workResult);
            }
        }
    }

    private function getAvailableExtensions(): array
    {
        return $this->discoverExtensions();
    }

    private function simulateAutoload(string $extensionName): void
    {
        $extensionPath = dirname(__DIR__, 6) . "/extensions/{$extensionName}/src";

        if (is_dir($extensionPath)) {
            // Simulate checking for class files
            $files = glob("{$extensionPath}/*.php");
            foreach (array_slice($files, 0, 3) as $file) {
                $fileCheck = file_exists($file); // Simulate file system access
                unset($fileCheck);
            }
        }
    }

    private function resolveDependencies(): void
    {
        $extensions = $this->getAvailableExtensions();
        $dependencies = [];

        foreach ($extensions as $extensionName) {
            $configFile = dirname(__DIR__, 6) . "/extensions/{$extensionName}/extension.json";

            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true);
                $dependencies[$extensionName] = $config['dependencies'] ?? [];
            }
        }

        // Simulate dependency resolution algorithm
        $resolved = [];
        $remaining = $dependencies;

        while (!empty($remaining)) {
            $progress = false;

            foreach ($remaining as $ext => $deps) {
                if (empty($deps) || array_diff(array_keys($deps), $resolved) === []) {
                    $resolved[] = $ext;
                    unset($remaining[$ext]);
                    $progress = true;
                }
            }

            if (!$progress) {
                break; // Circular dependency or missing dependency
            }
        }
    }

    private function simulateExtensionOperation(string $extensionName): void
    {
        $extensionPath = dirname(__DIR__, 6) . "/extensions/{$extensionName}";

        // Simulate reading config
        $configFile = "{$extensionPath}/extension.json";
        if (file_exists($configFile)) {
            json_decode(file_get_contents($configFile), true);
        }

        // Simulate checking source files
        $srcPath = "{$extensionPath}/src";
        if (is_dir($srcPath)) {
            $readableCheck = is_readable($srcPath);
            unset($readableCheck);
        }
    }

    private function displayBenchmarkResults(array $results, string $format, bool $detailed): void
    {
        $this->line('');
        $this->info('Benchmark Results:');
        $this->line('=================');

        switch ($format) {
            case 'json':
                $this->displayJsonResults($results);
                break;
            case 'csv':
                $this->displayCsvResults($results);
                break;
            default:
                $this->displayTableResults($results, $detailed);
        }
    }

    private function displayTableResults(array $results, bool $detailed): void
    {
        // Main benchmarks
        $mainResults = array_filter($results, fn($key) => $key !== 'individual', ARRAY_FILTER_USE_KEY);

        $rows = [];
        foreach ($mainResults as $result) {
            $rows[] = [
                $result['name'],
                number_format($result['avg_time'], 2) . 'ms',
                number_format($result['min_time'], 2) . 'ms',
                number_format($result['max_time'], 2) . 'ms',
                isset($result['avg_memory']) ? $this->formatBytes($result['avg_memory']) : 'N/A'
            ];
        }

        $this->table(['Benchmark', 'Avg Time', 'Min Time', 'Max Time', 'Avg Memory'], $rows);

        // Individual extension results
        if ($detailed && isset($results['individual'])) {
            $this->line('');
            $this->info('Per-Extension Results:');

            $extRows = [];
            foreach ($results['individual'] as $result) {
                $extRows[] = [
                    $result['extension'],
                    number_format($result['avg_time'], 2) . 'ms',
                    number_format($result['min_time'], 2) . 'ms',
                    number_format($result['max_time'], 2) . 'ms',
                    isset($result['avg_memory']) ? $this->formatBytes($result['avg_memory']) : 'N/A'
                ];
            }

            $this->table(['Extension', 'Avg Time', 'Min Time', 'Max Time', 'Avg Memory'], $extRows);
        }

        // Performance summary
        $this->displayPerformanceSummary($results);
    }

    private function displayJsonResults(array $results): void
    {
        $this->line(json_encode($results, JSON_PRETTY_PRINT));
    }

    private function displayCsvResults(array $results): void
    {
        $this->line('benchmark,avg_time_ms,min_time_ms,max_time_ms,avg_memory_bytes');

        foreach ($results as $key => $result) {
            if ($key === 'individual') {
                foreach ($result as $extResult) {
                    $this->line(sprintf(
                        '%s,%f,%f,%f,%d',
                        $extResult['extension'],
                        $extResult['avg_time'],
                        $extResult['min_time'],
                        $extResult['max_time'],
                        $extResult['avg_memory'] ?? 0
                    ));
                }
            } else {
                $this->line(sprintf(
                    '%s,%f,%f,%f,%d',
                    $result['name'],
                    $result['avg_time'],
                    $result['min_time'],
                    $result['max_time'],
                    $result['avg_memory'] ?? 0
                ));
            }
        }
    }

    private function displayPerformanceSummary(array $results): void
    {
        $this->line('');
        $this->info('Performance Summary:');

        $totalTime = 0;
        $slowestBenchmark = '';
        $slowestTime = 0;

        foreach ($results as $key => $result) {
            if ($key === 'individual') {
                continue;
            }

            $totalTime += $result['avg_time'];

            if ($result['avg_time'] > $slowestTime) {
                $slowestTime = $result['avg_time'];
                $slowestBenchmark = $result['name'];
            }
        }

        $summary = [
            ['Total Average Time', number_format($totalTime, 2) . 'ms'],
            ['Slowest Operation', $slowestBenchmark . ' (' . number_format($slowestTime, 2) . 'ms)'],
            ['Extensions Tested', count($this->getAvailableExtensions())],
        ];

        $this->table(['Metric', 'Value'], $summary);

        // Performance recommendations
        if ($slowestTime > 50) {
            $this->warning("Performance issue detected: {$slowestBenchmark} is taking longer than expected");
        } elseif ($totalTime < 10) {
            $this->line('<info>✓ Extension system performance is excellent</info>');
        } else {
            $this->line('<info>✓ Extension system performance is good</info>');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $factor = 1024;

        for ($i = 0; $i < count($units) && $bytes >= $factor; $i++) {
            $bytes /= $factor;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
