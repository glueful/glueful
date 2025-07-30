<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Database;

use Glueful\Database\Connection;
use Glueful\Database\Tools\QueryProfilerService;
use Glueful\Database\Tools\ExecutionPlanAnalyzer;
use Glueful\Database\Tools\QueryPatternRecognizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database Query Profile Command
 * - Comprehensive query analysis and profiling
 * - Detailed execution metrics and performance insights
 * - Advanced execution plan analysis with bottleneck detection
 * - Query pattern recognition with optimization recommendations
 * - Multiple output formats (table, JSON, detailed analysis)
 * - File-based query input support
 * - Performance comparison and benchmarking
 * @package Glueful\Console\Commands\Database
 */
#[AsCommand(
    name: 'db:profile',
    description: 'Profile and analyze database queries for performance optimization'
)]
class ProfileCommand extends BaseCommand
{
    private Connection $connection;
    private QueryProfilerService $profilerService;
    private ExecutionPlanAnalyzer $planAnalyzer;
    private QueryPatternRecognizer $patternRecognizer;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Profile and analyze database queries for performance optimization')
             ->setHelp('This command profiles SQL queries, analyzes execution plans, ' .
                      'identifies patterns, and provides optimization recommendations.')
             ->addArgument(
                 'query',
                 InputArgument::OPTIONAL,
                 'SQL query to profile (use quotes for complex queries)'
             )
             ->addOption(
                 'file',
                 'f',
                 InputOption::VALUE_REQUIRED,
                 'File containing SQL query to profile'
             )
             ->addOption(
                 'params',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Query parameters as JSON string'
             )
             ->addOption(
                 'explain',
                 'e',
                 InputOption::VALUE_NONE,
                 'Include execution plan analysis'
             )
             ->addOption(
                 'patterns',
                 null,
                 InputOption::VALUE_NONE,
                 'Detect query patterns and provide recommendations'
             )
             ->addOption(
                 'output',
                 'o',
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, detailed)',
                 'table'
             )
             ->addOption(
                 'benchmark',
                 'b',
                 InputOption::VALUE_REQUIRED,
                 'Run query multiple times for benchmarking',
                 '1'
             )
             ->addOption(
                 'compare',
                 'c',
                 InputOption::VALUE_REQUIRED,
                 'Compare with another query from file'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        // Get query from arguments or file
        $query = $this->getQuery($input);
        if (empty($query)) {
            $this->io->error('No query provided. Use the query argument or --file option.');
            return self::FAILURE;
        }

        // Parse parameters
        $params = $this->parseParams($input->getOption('params'));
        $format = $input->getOption('output');
        $benchmark = (int) $input->getOption('benchmark');
        $compareFile = $input->getOption('compare');

        try {
            $this->io->title('🔍 Database Query Profile Analysis');

            // Profile the main query
            $profile = $this->profileQuery(
                $query,
                $params,
                $benchmark,
                $input->getOption('explain'),
                $input->getOption('patterns')
            );

            // Compare with another query if requested
            $comparison = null;
            if ($compareFile) {
                $comparison = $this->compareQueries($query, $compareFile, $params);
            }

            // Output results
            $this->outputResults($profile, $comparison, $format);

            // Show recommendations
            $this->showRecommendations($profile);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->io->error('Profile analysis failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $this->io->text($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $this->connection = new Connection();
        $this->profilerService = new QueryProfilerService();
        $this->planAnalyzer = new ExecutionPlanAnalyzer($this->connection);
        $this->patternRecognizer = new QueryPatternRecognizer();
    }

    private function getQuery(InputInterface $input): string
    {
        // Get query from argument
        $query = $input->getArgument('query');
        if (!empty($query)) {
            return $query;
        }

        // Get query from file
        $file = $input->getOption('file');
        if ($file) {
            if (!file_exists($file)) {
                throw new \RuntimeException("Query file not found: {$file}");
            }
            return file_get_contents($file);
        }

        return '';
    }

    private function parseParams(?string $params): array
    {
        if (empty($params)) {
            return [];
        }

        try {
            $decoded = json_decode($params, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException $e) {
            $this->io->warning('Invalid JSON in parameters. Using empty parameters.');
            return [];
        }
    }

    private function profileQuery(
        string $query,
        array $params,
        int $benchmark,
        bool $includeExplain,
        bool $includePatterns
    ): array {
        $profiles = [];
        $totalTime = 0;
        $this->io->section('📊 Profiling Query');

        // Show query being analyzed
        $this->io->text('<info>Query:</info>');
        $this->io->text($query);
        if (!empty($params)) {
            $this->io->text('<info>Parameters:</info>');
            $this->io->text(json_encode($params, JSON_PRETTY_PRINT));
        }
        $this->io->newLine();

        // Run benchmark iterations
        $progressBar = null;
        if ($benchmark > 1) {
            $this->io->text("Running {$benchmark} iterations for benchmarking...");
            $progressBar = $this->io->createProgressBar($benchmark);
            $progressBar->start();
        }

        for ($i = 0; $i < $benchmark; $i++) {
            $result = $this->profilerService->profile($query, $params, function () use ($query, $params) {
                // Execute raw query directly using Connection
                $stmt = $this->connection->getPDO()->prepare($query);
                $stmt->execute($params);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            });

            $recentProfiles = $this->profilerService->getRecentProfiles(1);
            if (!empty($recentProfiles)) {
                $profile = $recentProfiles[0];
                $profile['result_count'] = is_countable($result) ? count($result) : 'N/A';
                $profile['result_sample'] = $this->getSampleResults($result, 3);
                $profiles[] = $profile;
                $totalTime += $profile['duration'] ?? 0;
            }

            if ($progressBar !== null) {
                $progressBar->advance();
            }
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->io->newLine(2);
        }

        // Calculate benchmark statistics
        $avgProfile = $this->calculateBenchmarkStats($profiles, $totalTime, $benchmark);

        // Add execution plan analysis
        if ($includeExplain) {
            $this->io->text('🔎 Analyzing execution plan...');
            try {
                $plan = $this->planAnalyzer->getExecutionPlan($query, $params);
                $analysis = $this->planAnalyzer->analyzeExecutionPlan($plan);
                $avgProfile['execution_plan'] = $plan;
                $avgProfile['plan_analysis'] = $analysis;
            } catch (\Exception $exception) {
                $this->io->warning('Execution plan analysis failed: ' . $exception->getMessage());
            }
        }

        // Add pattern recognition
        if ($includePatterns) {
            $this->io->text('🧩 Recognizing query patterns...');
            $patterns = $this->patternRecognizer->recognizePatterns($query);
            $avgProfile['patterns'] = $patterns;
        }

        return $avgProfile;
    }

    private function getSampleResults($result, int $limit = 5): array
    {
        if (!is_array($result) || empty($result)) {
            return [];
        }

        return array_slice($result, 0, $limit);
    }

    private function calculateBenchmarkStats(array $profiles, float $totalTime, int $benchmark): array
    {
        if (empty($profiles)) {
            return [];
        }

        $firstProfile = $profiles[0];
        $durations = array_column($profiles, 'duration');
        $memories = array_column($profiles, 'memory_delta');

        return array_merge($firstProfile, [
            'benchmark_iterations' => $benchmark,
            'total_time' => $totalTime,
            'avg_duration' => array_sum($durations) / count($durations),
            'min_duration' => min($durations),
            'max_duration' => max($durations),
            'avg_memory' => array_sum($memories) / count($memories),
            'duration_variance' => $this->calculateVariance($durations),
        ]);
    }

    private function calculateVariance(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $sumSquares = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values));
        return $sumSquares / count($values);
    }

    private function compareQueries(string $mainQuery, string $compareFile, array $params): array
    {
        if (!file_exists($compareFile)) {
            throw new \RuntimeException("Comparison query file not found: {$compareFile}");
        }

        $compareQuery = file_get_contents($compareFile);
        $this->io->section('⚖️ Query Comparison');

        // Profile comparison query
        $compareProfile = $this->profileQuery($compareQuery, $params, 1, false, false);

        return [
            'main_query' => $mainQuery,
            'compare_query' => $compareQuery,
            'main_profile' => $this->profilerService->getRecentProfiles(1)[0] ?? [],
            'compare_profile' => $compareProfile,
        ];
    }

    private function outputResults(array $profile, ?array $comparison, string $format): void
    {
        switch ($format) {
            case 'json':
                $this->outputJson($profile, $comparison);
                break;
            case 'detailed':
                $this->outputDetailed($profile, $comparison);
                break;
            default:
                $this->outputTable($profile, $comparison);
                break;
        }
    }

    private function outputJson(array $profile, ?array $comparison): void
    {
        $output = ['profile' => $profile];
        if ($comparison) {
            $output['comparison'] = $comparison;
        }
        $this->io->text(json_encode($output, JSON_PRETTY_PRINT));
    }

    private function outputTable(array $profile, ?array $comparison): void
    {
        $this->io->section('📈 Performance Metrics');

        $rows = [
            ['Metric', 'Value'],
        ];

        if (isset($profile['benchmark_iterations']) && $profile['benchmark_iterations'] > 1) {
            $rows[] = ['Benchmark Iterations', number_format($profile['benchmark_iterations'])];
            $rows[] = ['Average Duration', number_format($profile['avg_duration'], 2) . ' ms'];
            $rows[] = ['Min Duration', number_format($profile['min_duration'], 2) . ' ms'];
            $rows[] = ['Max Duration', number_format($profile['max_duration'], 2) . ' ms'];
            $rows[] = ['Duration Variance', number_format($profile['duration_variance'], 4)];
            $rows[] = ['Total Time', number_format($profile['total_time'], 2) . ' ms'];
        } else {
            $rows[] = ['Duration', number_format($profile['duration'] ?? 0, 2) . ' ms'];
        }

        $rows[] = ['Memory Usage', $this->formatBytes($profile['memory_delta'] ?? 0)];
        $rows[] = ['Result Count', $profile['result_count'] ?? 'N/A'];

        $this->io->table($rows[0], array_slice($rows, 1));

        // Show execution plan issues if available
        if (isset($profile['plan_analysis']['issues']) && !empty($profile['plan_analysis']['issues'])) {
            $this->io->section('⚠️ Execution Plan Issues');
            foreach ($profile['plan_analysis']['issues'] as $issue) {
                $this->io->warning($issue);
            }
        }

        // Show comparison if available
        if ($comparison) {
            $this->outputComparison($comparison);
        }
    }

    private function outputDetailed(array $profile, ?array $comparison): void
    {
        $this->outputTable($profile, $comparison);

        // Show execution plan
        if (isset($profile['execution_plan'])) {
            $this->io->section('🗂️ Execution Plan');
            $this->io->text(json_encode($profile['execution_plan'], JSON_PRETTY_PRINT));
        }

        // Show patterns
        if (isset($profile['patterns']) && !empty($profile['patterns'])) {
            $this->io->section('🧩 Query Patterns');
            foreach ($profile['patterns'] as $patternName => $info) {
                $this->io->text("<info>Pattern:</info> {$patternName}");
                $this->io->text("<comment>Description:</comment> {$info['description']}");
                $this->io->text("<comment>Recommendation:</comment> {$info['recommendation']}");
                $this->io->newLine();
            }
        }

        // Show result sample
        if (!empty($profile['result_sample'])) {
            $this->io->section('📋 Result Sample');
            foreach ($profile['result_sample'] as $i => $row) {
                $this->io->text("Row " . ($i + 1) . ":");
                $this->io->text(json_encode($row, JSON_PRETTY_PRINT));
                $this->io->newLine();
            }
        }
    }

    private function outputComparison(array $comparison): void
    {
        $this->io->section('⚖️ Performance Comparison');

        $main = $comparison['main_profile'];
        $compare = $comparison['compare_profile'];

        $mainDuration = $main['duration'] ?? 0;
        $compareDuration = $compare['duration'] ?? 0;
        $difference = $mainDuration - $compareDuration;
        $percentDiff = $compareDuration > 0 ? (($difference / $compareDuration) * 100) : 0;

        $rows = [
            ['Metric', 'Main Query', 'Comparison Query', 'Difference'],
            [
                'Duration (ms)',
                number_format($mainDuration, 2),
                number_format($compareDuration, 2),
                ($difference >= 0 ? '+' : '') . number_format($difference, 2) . ' (' .
                ($percentDiff >= 0 ? '+' : '') . number_format($percentDiff, 1) . '%)'
            ],
            [
                'Memory (bytes)',
                $this->formatBytes($main['memory_delta'] ?? 0),
                $this->formatBytes($compare['memory_delta'] ?? 0),
                $this->formatBytes(($main['memory_delta'] ?? 0) - ($compare['memory_delta'] ?? 0))
            ]
        ];

        $this->io->table($rows[0], array_slice($rows, 1));

        if ($difference > 0) {
            $this->io->warning('Main query is slower than comparison query');
        } elseif ($difference < 0) {
            $this->io->success('Main query is faster than comparison query');
        } else {
            $this->io->info('Queries have similar performance');
        }
    }

    private function showRecommendations(array $profile): void
    {
        $recommendations = [];

        // Duration-based recommendations
        $duration = $profile['avg_duration'] ?? $profile['duration'] ?? 0;
        if ($duration > 1000) {
            $recommendations[] = 'Query takes over 1 second - consider adding indexes or optimizing the query';
        } elseif ($duration > 100) {
            $recommendations[] = 'Query performance could be improved - review execution plan';
        }

        // Memory-based recommendations
        $memory = $profile['memory_delta'] ?? 0;
        if ($memory > 10 * 1024 * 1024) { // 10MB
            $recommendations[] = 'High memory usage detected - consider result set size optimization';
        }

        // Variance-based recommendations for benchmarks
        if (isset($profile['duration_variance']) && $profile['duration_variance'] > 100) {
            $recommendations[] = 'High performance variance detected - query may be affected by external factors';
        }

        // Add execution plan recommendations
        if (isset($profile['plan_analysis']['recommendations'])) {
            $recommendations = array_merge($recommendations, $profile['plan_analysis']['recommendations']);
        }

        if (!empty($recommendations)) {
            $this->io->section('💡 Optimization Recommendations');
            foreach ($recommendations as $recommendation) {
                $this->io->text('• ' . $recommendation);
            }
        } else {
            $this->io->success('No specific performance issues detected');
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
