<?php

namespace Glueful\Console\Commands;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Glueful\Http\Router;
use Glueful\Extensions\ExtensionManager;
use Glueful\Helpers\RoutesManager;
use Glueful\Services\RouteCacheService;

/**
 * Unified Route Management Command
 *
 * Handles all route-related operations including caching and clearing.
 * Provides a consistent interface for route management tasks.
 *
 * @package Glueful\Console\Commands
 */
#[AsCommand(
    name: 'route',
    description: 'Manage application routes (cache, clear, list)'
)]
class RouteCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Manage application routes (cache, clear, list)')
             ->setHelp('This command manages route operations for the Glueful framework.')
             ->addArgument(
                 'action',
                 InputArgument::REQUIRED,
                 'The action to perform (cache, clear, list, status)'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force operation even if cache exists (for cache action)'
             )
             ->addOption(
                 'analyze',
                 'a',
                 InputOption::VALUE_NONE,
                 'Show detailed analysis and performance metrics'
             )
             ->addOption(
                 'verbose',
                 'v',
                 InputOption::VALUE_NONE,
                 'Show verbose output'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');

        $output->writeln('<info>🚀 Glueful Route Manager</info>');
        $output->writeln('');

        return match ($action) {
            'cache' => $this->handleCacheAction($input, $output),
            'clear' => $this->handleClearAction($input, $output),
            'list' => $this->handleListAction($input, $output),
            'status' => $this->handleStatusAction($input, $output),
            default => $this->handleInvalidAction($action, $output)
        };
    }

    /**
     * Handle route caching
     */
    private function handleCacheAction(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $analyze = $input->getOption('analyze');

        try {
            $cacheService = new RouteCacheService();
            $cacheFile = $cacheService->getCacheFilePath();

            // Check if cache already exists
            if (!$force && file_exists($cacheFile)) {
                $output->writeln('<comment>⚠️  Route cache already exists!</comment>');
                $output->writeln("Cache file: {$cacheFile}");
                $output->writeln('Use --force to regenerate the cache.');
                return self::SUCCESS;
            }

            if ($force && file_exists($cacheFile)) {
                $output->writeln('<comment>🗑️  Removing existing cache file...</comment>');
                unlink($cacheFile);
            }

            $output->writeln('<info>📋 Loading and compiling routes...</info>');

            // Create a fresh router instance for compilation
            $router = Router::getInstance();
            $startTime = microtime(true);
            $memoryBefore = memory_get_usage(true);

            // Load extensions first (they may register routes)
            $output->writeln('   • Loading extensions...');
            $extensionManager = container()->get(ExtensionManager::class);
            $extensionManager->loadEnabledExtensions();
            $extensionManager->loadExtensionRoutes();

            // Load core routes
            $output->writeln('   • Loading core routes...');
            RoutesManager::loadRoutes();

            $loadTime = microtime(true) - $startTime;
            $memoryAfter = memory_get_usage(true);
            $memoryUsed = $memoryAfter - $memoryBefore;

            // Compile routes to cache
            $output->writeln('<info>💾 Compiling routes to cache...</info>');
            $compileStartTime = microtime(true);

            $result = $cacheService->cacheRoutes($router);

            $compileTime = microtime(true) - $compileStartTime;
            $totalTime = microtime(true) - $startTime;

            if ($result['success']) {
                $output->writeln('<success>✅ Route cache created successfully!</success>');
                $output->writeln('');
                $this->displayCacheStats($output, $result, $analyze, $loadTime, $compileTime, $totalTime, $memoryUsed);
                $this->displayCacheTips($output);
                return self::SUCCESS;
            } else {
                $output->writeln('<error>❌ Failed to create route cache!</error>');
                $output->writeln("Error: {$result['error']}");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            return $this->handleException($e, $output, 'Route cache generation failed!');
        }
    }

    /**
     * Handle route cache clearing
     */
    private function handleClearAction(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');

        try {
            $cacheService = new RouteCacheService();
            $cacheFile = $cacheService->getCacheFilePath();

            if ($verbose) {
                $output->writeln("<comment>Cache file location: {$cacheFile}</comment>");
                $output->writeln('');
            }

            if (!file_exists($cacheFile)) {
                $output->writeln('<comment>ℹ️  No route cache file found.</comment>');
                $output->writeln('Routes are already being loaded dynamically.');
                return self::SUCCESS;
            }

            // Get cache file info before deletion
            $cacheSize = filesize($cacheFile);
            $cacheModified = filemtime($cacheFile);

            if ($verbose) {
                $this->displayCacheInfo($output, $cacheSize, $cacheModified);
            }

            // Remove the cache file
            $output->writeln('<info>🗑️  Removing route cache...</info>');

            if (unlink($cacheFile)) {
                $output->writeln('<success>✅ Route cache cleared successfully!</success>');
                $output->writeln('');

                if ($verbose) {
                    $this->displayClearResults($output, $cacheSize, $cacheFile);
                }

                $this->displayClearTips($output);
                return self::SUCCESS;
            } else {
                $output->writeln('<error>❌ Failed to remove route cache file!</error>');
                $output->writeln('Check file permissions and try again.');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            return $this->handleException($e, $output, 'Route cache clearing failed!');
        }
    }

    /**
     * Handle route listing
     */
    private function handleListAction(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');

        try {
            // Initialize router to load routes
            $router = Router::getInstance();
            $extensionManager = container()->get(ExtensionManager::class);
            $extensionManager->loadEnabledExtensions();
            $extensionManager->loadExtensionRoutes();
            RoutesManager::loadRoutes();

            $routes = Router::getRoutes();
            $routeCount = count($routes);

            $output->writeln("<info>📋 Found {$routeCount} registered routes:</info>");
            $output->writeln('');

            if ($routeCount === 0) {
                $output->writeln('<comment>No routes found.</comment>');
                return self::SUCCESS;
            }

            $tableData = [];
            foreach ($routes as $name => $route) {
                $methods = implode('|', $route->getMethods() ?: ['ANY']);
                $path = $route->getPath();

                if ($verbose) {
                    $defaults = $route->getDefaults();
                    $controller = $defaults['_controller'] ?? 'N/A';
                    $tableData[] = [$methods, $path, $controller, $name];
                } else {
                    $tableData[] = [$methods, $path, $name];
                }
            }

            // Display as simple formatted output instead of table for CLI simplicity
            $headers = $verbose ? ['Method', 'Path', 'Controller', 'Name'] : ['Method', 'Path', 'Name'];

            // Simple text table
            foreach ($tableData as $row) {
                if ($verbose) {
                    $output->writeln(sprintf(
                        '<info>%s</info> <comment>%s</comment> -> %s (%s)',
                        str_pad($row[0], 10),
                        str_pad($row[1], 30),
                        $row[2],
                        $row[3]
                    ));
                } else {
                    $output->writeln(sprintf(
                        '<info>%s</info> <comment>%s</comment> (%s)',
                        str_pad($row[0], 10),
                        str_pad($row[1], 30),
                        $row[2]
                    ));
                }
            }

            $output->writeln('');
            $output->writeln("<info>Total: {$routeCount} routes</info>");

            return self::SUCCESS;
        } catch (\Exception $e) {
            return $this->handleException($e, $output, 'Route listing failed!');
        }
    }

    /**
     * Handle route status
     */
    private function handleStatusAction(InputInterface $input, OutputInterface $output): int
    {
        try {
            $cacheService = new RouteCacheService();
            $cacheFile = $cacheService->getCacheFilePath();
            $isCacheValid = $cacheService->isCacheValid();

            $output->writeln('<info>🔍 Route System Status:</info>');
            $output->writeln('');

            // Environment info
            $environment = $_ENV['APP_ENV'] ?? 'development';
            $debug = $_ENV['APP_DEBUG'] ?? 'true';
            $shouldUseCache = $environment === 'production' && (strtolower($debug) === 'false' || $debug === '0');

            $output->writeln("<info>Environment:</info> {$environment}");
            $output->writeln("<info>Debug mode:</info> {$debug}");
            $output->writeln("<info>Should use cache:</info> " . ($shouldUseCache ? '✅ Yes' : '❌ No'));
            $output->writeln('');

            // Cache status
            $output->writeln('<info>Cache Status:</info>');
            $output->writeln("<info>Cache file exists:</info> " . (file_exists($cacheFile) ? '✅ Yes' : '❌ No'));

            if (file_exists($cacheFile)) {
                $output->writeln("<info>Cache file valid:</info> " . ($isCacheValid ? '✅ Yes' : '❌ No'));
                $output->writeln("<info>Cache file location:</info> {$cacheFile}");

                $cacheSize = filesize($cacheFile);
                $cacheModified = filemtime($cacheFile);
                $output->writeln("<info>Cache file size:</info> " . $this->formatBytes($cacheSize));
                $output->writeln("<info>Last modified:</info> " . date('Y-m-d H:i:s', $cacheModified));
                $output->writeln("<info>Age:</info> " . $this->formatAge(time() - $cacheModified));

                if ($isCacheValid) {
                    $cached = include $cacheFile;
                    $routeCount = count($cached['routes']);
                    $output->writeln("<info>Cached routes:</info> {$routeCount}");
                }
            }

            $output->writeln('');

            // Current router status
            $usingCache = Router::isUsingCachedRoutes();
            $output->writeln("<info>Currently using cache:</info> " . ($usingCache ? '✅ Yes' : '❌ No'));

            // Load current routes to get count
            $router = Router::getInstance();
            $extensionManager = container()->get(ExtensionManager::class);
            $extensionManager->loadEnabledExtensions();
            $extensionManager->loadExtensionRoutes();
            RoutesManager::loadRoutes();

            $currentRoutes = Router::getRoutes();
            $currentRouteCount = count($currentRoutes);
            $output->writeln("<info>Active routes:</info> {$currentRouteCount}");

            $output->writeln('');

            // Recommendations
            $output->writeln('<info>💡 Recommendations:</info>');
            if ($shouldUseCache && !$isCacheValid) {
                $output->writeln('   • Run "php glueful route cache" to create cache for production');
            } elseif (!$shouldUseCache && $isCacheValid) {
                $output->writeln('   • Cache exists but not used in development (this is normal)');
            } elseif ($shouldUseCache && $isCacheValid) {
                $output->writeln('   • ✅ Cache is properly configured and active');
            } else {
                $output->writeln('   • Development mode: routes loaded dynamically (normal)');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            return $this->handleException($e, $output, 'Route status check failed!');
        }
    }

    /**
     * Handle invalid action
     */
    private function handleInvalidAction(string $action, OutputInterface $output): int
    {
        $output->writeln("<error>❌ Unknown action: {$action}</error>");
        $output->writeln('');
        $output->writeln('<info>Available actions:</info>');
        $output->writeln('   • <comment>cache</comment>  - Create route cache for production performance');
        $output->writeln('   • <comment>clear</comment>  - Remove route cache (for development)');
        $output->writeln('   • <comment>list</comment>   - List all registered routes');
        $output->writeln('   • <comment>status</comment> - Show route system status');
        $output->writeln('');
        $output->writeln('<info>Examples:</info>');
        $output->writeln('   php glueful route cache --analyze');
        $output->writeln('   php glueful route clear --verbose');
        $output->writeln('   php glueful route list --verbose');
        $output->writeln('   php glueful route status');

        return self::FAILURE;
    }

    /**
     * Handle exceptions consistently
     */
    private function handleException(\Exception $e, OutputInterface $output, string $message): int
    {
        $output->writeln("<error>❌ {$message}</error>");
        $output->writeln("Error: {$e->getMessage()}");
        $output->writeln("File: {$e->getFile()}:{$e->getLine()}");

        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $output->writeln('');
            $output->writeln('<comment>Stack trace:</comment>');
            $output->writeln($e->getTraceAsString());
        }

        return self::FAILURE;
    }

    /**
     * Display cache statistics
     */
    private function displayCacheStats(
        OutputInterface $output,
        array $result,
        bool $analyze,
        float $loadTime,
        float $compileTime,
        float $totalTime,
        int $memoryUsed
    ): void {
        $output->writeln('<info>📊 Cache Statistics:</info>');
        $output->writeln("   • Routes compiled: {$result['stats']['total_routes']}");
        $output->writeln("   • Protected routes: {$result['stats']['protected_routes']}");
        $output->writeln("   • Admin routes: {$result['stats']['admin_routes']}");
        $output->writeln("   • Route groups: {$result['stats']['route_groups']}");
        $output->writeln("   • Cache file size: " . $this->formatBytes($result['stats']['cache_size']));
        $output->writeln("   • Cache file: {$result['cache_file']}");
        $output->writeln('');

        if ($analyze) {
            $output->writeln('<info>⚡ Performance Analysis:</info>');
            $output->writeln("   • Route loading time: " . number_format($loadTime * 1000, 2) . "ms");
            $output->writeln("   • Cache compilation time: " . number_format($compileTime * 1000, 2) . "ms");
            $output->writeln("   • Total time: " . number_format($totalTime * 1000, 2) . "ms");
            $output->writeln("   • Memory used: " . $this->formatBytes($memoryUsed));
            $output->writeln('   • Expected production speedup: ~50-70%');
            $output->writeln('');
        }
    }

    /**
     * Display cache tips
     */
    private function displayCacheTips(OutputInterface $output): void
    {
        $output->writeln('<comment>💡 Tips for maximum performance:</comment>');
        $output->writeln('   • Deploy this cache file to production');
        $output->writeln('   • Set APP_ENV=production in .env');
        $output->writeln('   • Use "php glueful route clear" during development');
        $output->writeln('   • Regenerate cache after route changes');
    }

    /**
     * Display cache file information
     */
    private function displayCacheInfo(OutputInterface $output, int $cacheSize, int $cacheModified): void
    {
        $output->writeln('<info>📋 Cache file information:</info>');
        $output->writeln("   • Size: " . $this->formatBytes($cacheSize));
        $output->writeln("   • Last modified: " . date('Y-m-d H:i:s', $cacheModified));
        $output->writeln("   • Age: " . $this->formatAge(time() - $cacheModified));
        $output->writeln('');
    }

    /**
     * Display clear results
     */
    private function displayClearResults(OutputInterface $output, int $cacheSize, string $cacheFile): void
    {
        $output->writeln('<info>📊 Clearing Results:</info>');
        $output->writeln("   • Freed space: " . $this->formatBytes($cacheSize));
        $output->writeln("   • Cache file removed: {$cacheFile}");
        $output->writeln('   • Routes will now be loaded dynamically');
        $output->writeln('');
    }

    /**
     * Display clear tips
     */
    private function displayClearTips(OutputInterface $output): void
    {
        $output->writeln('<comment>💡 What happens next:</comment>');
        $output->writeln('   • Routes will be loaded from source files on each request');
        $output->writeln('   • This is normal for development environments');
        $output->writeln('   • Use "php glueful route cache" to recreate cache for production');
        $output->writeln('   • Performance impact: ~50% slower route resolution');
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format age in human readable format
     */
    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . ' hours';
        } else {
            return round($seconds / 86400) . ' days';
        }
    }
}
