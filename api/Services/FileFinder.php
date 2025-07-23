<?php

declare(strict_types=1);

namespace Glueful\Services;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Psr\Log\LoggerInterface;
use Iterator;

/**
 * File Finder Service
 *
 * Provides advanced file and directory discovery using Symfony Finder component.
 * Wraps Symfony Finder with application-specific methods for common use cases.
 */
class FileFinder
{
    private ?LoggerInterface $logger;
    private array $config;

    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'enable_logging' => true,
            'default_depth' => null,
            'follow_links' => false,
            'ignore_vcs' => true,
            'ignore_dot_files' => true,
        ], $config);
    }

    /**
     * Find extensions in the extensions directory
     *
     * @param string $extensionsPath Path to extensions directory
     * @return Iterator<SplFileInfo> Extension directories
     */
    public function findExtensions(string $extensionsPath): Iterator
    {
        $finder = $this->createFinder();

        $extensions = $finder
            ->directories()
            ->in($extensionsPath)
            ->depth('== 0')
            ->filter(function (SplFileInfo $dir) {
                // Check if directory contains Extension.php file
                return file_exists($dir->getPathname() . '/Extension.php');
            });

        $this->log('info', "Found extensions in: {$extensionsPath}");
        return $extensions->getIterator();
    }

    /**
     * Find route files across multiple directories
     *
     * @param array $paths Array of paths to search
     * @param array $patterns File name patterns (default: ['*.php'])
     * @return Iterator<SplFileInfo> Route files
     */
    public function findRouteFiles(array $paths, array $patterns = ['*.php']): Iterator
    {
        $existingPaths = array_filter($paths, 'is_dir');

        if (empty($existingPaths)) {
            $this->log('warning', 'No valid route paths found: ' . implode(', ', $paths));
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $routes = $finder
            ->files()
            ->in($existingPaths);

        foreach ($patterns as $pattern) {
            $routes->name($pattern);
        }

        $routes->sortByName();

        $this->log('info', "Found route files in paths: " . implode(', ', $existingPaths));
        return $routes->getIterator();
    }

    /**
     * Find migration files
     *
     * @param string $migrationsPath Path to migrations directory
     * @param string $pattern File name pattern (default: '*.php')
     * @return Iterator<SplFileInfo> Migration files sorted by name
     */
    public function findMigrations(string $migrationsPath, string $pattern = '*.php'): Iterator
    {
        if (!is_dir($migrationsPath)) {
            $this->log('warning', "Migrations directory does not exist: {$migrationsPath}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $migrations = $finder
            ->files()
            ->in($migrationsPath)
            ->name($pattern)
            ->filter(function (SplFileInfo $file) {
                // Only include files that match migration naming pattern (e.g., 001_CreateUsersTable.php)
                return preg_match('/^\d{3}_.*\.php$/', $file->getBasename());
            })
            ->sortByName();

        $this->log('info', "Found migrations in: {$migrationsPath}");
        return $migrations->getIterator();
    }

    /**
     * Find cache files with optional filtering
     *
     * @param string $cachePath Path to cache directory
     * @param string $pattern File pattern (default: '*')
     * @param string|null $olderThan Date string for old files (e.g., '7 days ago')
     * @return Iterator<SplFileInfo> Cache files
     */
    public function findCacheFiles(string $cachePath, string $pattern = '*', ?string $olderThan = null): Iterator
    {
        if (!is_dir($cachePath)) {
            $this->log('warning', "Cache directory does not exist: {$cachePath}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $cache = $finder
            ->files()
            ->in($cachePath)
            ->name($pattern);

        if ($olderThan !== null) {
            $cache->date("< {$olderThan}");
        }

        $this->log('info', "Found cache files in: {$cachePath}" . ($olderThan ? " (older than {$olderThan})" : ''));
        return $cache->getIterator();
    }

    /**
     * Find configuration files
     *
     * @param string $configPath Path to config directory
     * @param array $patterns File patterns (default: ['*.php'])
     * @return Iterator<SplFileInfo> Config files
     */
    public function findConfigFiles(string $configPath, array $patterns = ['*.php']): Iterator
    {
        if (!is_dir($configPath)) {
            $this->log('warning', "Config directory does not exist: {$configPath}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $configs = $finder
            ->files()
            ->in($configPath);

        foreach ($patterns as $pattern) {
            $configs->name($pattern);
        }

        $configs->sortByName();

        $this->log('info', "Found config files in: {$configPath}");
        return $configs->getIterator();
    }

    /**
     * Find log files
     *
     * @param string $logsPath Path to logs directory
     * @param string|null $olderThan Date string for old files
     * @param array $patterns File patterns (default: ['*.log'])
     * @return Iterator<SplFileInfo> Log files
     */
    public function findLogFiles(string $logsPath, ?string $olderThan = null, array $patterns = ['*.log']): Iterator
    {
        if (!is_dir($logsPath)) {
            $this->log('warning', "Logs directory does not exist: {$logsPath}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $logs = $finder
            ->files()
            ->in($logsPath);

        foreach ($patterns as $pattern) {
            $logs->name($pattern);
        }

        if ($olderThan !== null) {
            $logs->date("< {$olderThan}");
        }

        $logs->sortByModifiedTime();

        $this->log('info', "Found log files in: {$logsPath}" . ($olderThan ? " (older than {$olderThan})" : ''));
        return $logs->getIterator();
    }

    /**
     * Find PHP files in directory tree
     *
     * @param string $path Root path to search
     * @param int|null $maxDepth Maximum depth to search
     * @param array $excludeDirs Directories to exclude
     * @return Iterator<SplFileInfo> PHP files
     */
    public function findPhpFiles(
        string $path,
        ?int $maxDepth = null,
        array $excludeDirs = ['vendor', 'node_modules']
    ): Iterator {
        if (!is_dir($path)) {
            $this->log('warning', "Directory does not exist: {$path}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $files = $finder
            ->files()
            ->in($path)
            ->name('*.php');

        if ($maxDepth !== null) {
            $files->depth("<= {$maxDepth}");
        }

        foreach ($excludeDirs as $exclude) {
            $files->exclude($exclude);
        }

        $this->log('info', "Found PHP files in: {$path}");
        return $files->getIterator();
    }

    /**
     * Find directories matching criteria
     *
     * @param string $path Root path to search
     * @param string $pattern Directory name pattern
     * @param int|null $maxDepth Maximum depth to search
     * @return Iterator<SplFileInfo> Directories
     */
    public function findDirectories(string $path, string $pattern = '*', ?int $maxDepth = null): Iterator
    {
        if (!is_dir($path)) {
            $this->log('warning', "Directory does not exist: {$path}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $dirs = $finder
            ->directories()
            ->in($path)
            ->name($pattern);

        if ($maxDepth !== null) {
            $dirs->depth("<= {$maxDepth}");
        }

        $this->log('info', "Found directories in: {$path}");
        return $dirs->getIterator();
    }

    /**
     * Find files by content (grep-like functionality)
     *
     * @param string $path Directory to search
     * @param string $content Content to search for
     * @param array $patterns File patterns to include
     * @return Iterator<SplFileInfo> Files containing the content
     */
    public function findFilesByContent(string $path, string $content, array $patterns = ['*.php']): Iterator
    {
        if (!is_dir($path)) {
            $this->log('warning', "Directory does not exist: {$path}");
            return new \EmptyIterator();
        }

        $finder = $this->createFinder();

        $files = $finder
            ->files()
            ->in($path)
            ->contains($content);

        foreach ($patterns as $pattern) {
            $files->name($pattern);
        }

        $this->log('info', "Found files containing '{$content}' in: {$path}");
        return $files->getIterator();
    }

    /**
     * Create a new Finder instance with default configuration
     *
     * @return Finder Configured finder instance
     */
    public function createFinder(): Finder
    {
        $finder = new Finder();

        if ($this->config['ignore_vcs']) {
            $finder->ignoreVCS(true);
        }

        if ($this->config['ignore_dot_files']) {
            $finder->ignoreDotFiles(true);
        }

        if (!$this->config['follow_links']) {
            $finder->followLinks();
        }

        if ($this->config['default_depth'] !== null) {
            $finder->depth($this->config['default_depth']);
        }

        return $finder;
    }

    /**
     * Get file statistics for a directory
     *
     * @param string $path Directory path
     * @param array $patterns File patterns to include
     * @return array Statistics (file count, total size, etc.)
     */
    public function getDirectoryStats(string $path, array $patterns = ['*']): array
    {
        if (!is_dir($path)) {
            return ['error' => 'Directory does not exist'];
        }

        $finder = $this->createFinder();
        $files = $finder->files()->in($path);

        foreach ($patterns as $pattern) {
            $files->name($pattern);
        }

        $stats = [
            'file_count' => 0,
            'total_size' => 0,
            'largest_file' => null,
            'smallest_file' => null,
            'newest_file' => null,
            'oldest_file' => null,
        ];

        foreach ($files as $file) {
            $stats['file_count']++;
            $size = $file->getSize();
            $stats['total_size'] += $size;

            if ($stats['largest_file'] === null || $size > $stats['largest_file']['size']) {
                $stats['largest_file'] = ['name' => $file->getFilename(), 'size' => $size];
            }

            if ($stats['smallest_file'] === null || $size < $stats['smallest_file']['size']) {
                $stats['smallest_file'] = ['name' => $file->getFilename(), 'size' => $size];
            }

            $mtime = $file->getMTime();
            if ($stats['newest_file'] === null || $mtime > $stats['newest_file']['time']) {
                $stats['newest_file'] = ['name' => $file->getFilename(), 'time' => $mtime];
            }

            if ($stats['oldest_file'] === null || $mtime < $stats['oldest_file']['time']) {
                $stats['oldest_file'] = ['name' => $file->getFilename(), 'time' => $mtime];
            }
        }

        $this->log('info', "Generated stats for directory: {$path}");
        return $stats;
    }

    /**
     * Log operation if logger is available
     *
     * @param string $level Log level
     * @param string $message Log message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger && $this->config['enable_logging']) {
            $this->logger->log($level, $message, ['service' => 'FileFinder']);
        }
    }

    /**
     * Set configuration option
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public function setConfig(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Get configuration option
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Configuration value
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
