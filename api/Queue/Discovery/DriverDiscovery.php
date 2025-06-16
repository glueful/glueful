<?php

namespace Glueful\Queue\Discovery;

use Glueful\Queue\Contracts\QueueDriverInterface;

/**
 * Driver Discovery System
 *
 * Automatically discovers and analyzes queue drivers from multiple sources.
 * Supports core drivers, extensions, vendor packages, and custom implementations.
 *
 * Discovery Sources:
 * - Core drivers in Queue/Drivers
 * - Extension drivers in extensions/Queue/Drivers
 * - Custom user drivers in app/Queue/Drivers
 *
 * Features:
 * - Automatic driver class detection
 * - Interface validation and compliance checking
 * - Driver metadata extraction
 * - Error handling and logging
 * - Extensible search path configuration
 *
 * @package Glueful\Queue\Discovery
 */
class DriverDiscovery
{
    /** @var array Default search paths for driver discovery */
    private array $searchPaths = [
        // Core drivers
        __DIR__ . '/../Drivers',
        // Extension drivers - dynamic paths
        // Will be expanded at runtime
    ];

    /**
     * Discover all available queue drivers
     *
     * @return array Discovered drivers indexed by name
     */
    public function discoverDrivers(): array
    {
        $drivers = [];
        $searchPaths = $this->getExpandedSearchPaths();

        foreach ($searchPaths as $path) {
            $discovered = $this->scanPath($path);
            $drivers = array_merge($drivers, $discovered);
        }

        return $drivers;
    }

    /**
     * Get expanded search paths including dynamic paths
     *
     * @return array List of actual directory paths to search
     */
    private function getExpandedSearchPaths(): array
    {
        $paths = $this->searchPaths;

        // Add dynamic extension paths
        $basePath = dirname(__DIR__, 4);
        $extensionPaths = [
            $basePath . '/extensions/*/Queue/Drivers',
            $basePath . '/vendor/*/queue-drivers/src',
            $basePath . '/app/Queue/Drivers'
        ];

        foreach ($extensionPaths as $pattern) {
            $expandedPaths = glob($pattern, GLOB_ONLYDIR);
            if ($expandedPaths) {
                $paths = array_merge($paths, $expandedPaths);
            }
        }

        return array_filter($paths, 'is_dir');
    }

    /**
     * Scan a directory path for queue drivers
     *
     * @param string $path Directory path to scan
     * @return array Discovered drivers from this path
     */
    private function scanPath(string $path): array
    {
        $discovered = [];

        if (!is_dir($path)) {
            return $discovered;
        }

        $files = array_merge(
            glob($path . '/*Driver.php'),
            glob($path . '/*Queue.php')
        );
        foreach ($files as $file) {
            $driver = $this->analyzeDriverFile($file);
            if ($driver) {
                $discovered[$driver['info']->name] = $driver;
            }
        }

        return $discovered;
    }

    /**
     * Analyze a driver file for compliance and metadata
     *
     * @param string $file Path to driver file
     * @return array|null Driver information or null if invalid
     */
    private function analyzeDriverFile(string $file): ?array
    {
        $className = $this->extractClassName($file);
        if (!$className || !class_exists($className)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);

            // Validate driver compliance
            if (!$reflection->implementsInterface(QueueDriverInterface::class)) {
                return null;
            }

            if (!$reflection->isInstantiable()) {
                return null;
            }

            // Extract driver metadata
            $instance = $reflection->newInstanceWithoutConstructor();
            $info = $instance->getDriverInfo();

            return [
                'class' => $className,
                'file' => $file,
                'info' => $info,
                'reflection' => $reflection
            ];
        } catch (\Exception $e) {
            error_log("Failed to analyze driver {$className}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract class name from PHP file
     *
     * @param string $file Path to PHP file
     * @return string|null Fully qualified class name or null
     */
    private function extractClassName(string $file): ?string
    {
        $content = file_get_contents($file);
        if (!$content) {
            return null;
        }

        // Extract namespace
        $namespace = null;
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        // Extract class name
        $className = null;
        if (preg_match('/^class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
        }

        if (!$className) {
            return null;
        }

        return $namespace ? $namespace . '\\' . $className : $className;
    }

    /**
     * Add custom search path
     *
     * @param string $path Directory path to add
     * @return self Fluent interface
     */
    public function addSearchPath(string $path): self
    {
        if (is_dir($path) && !in_array($path, $this->searchPaths, true)) {
            $this->searchPaths[] = $path;
        }

        return $this;
    }

    /**
     * Get all configured search paths
     *
     * @return array List of search paths
     */
    public function getSearchPaths(): array
    {
        return $this->searchPaths;
    }

    /**
     * Validate discovered driver
     *
     * @param array $driver Driver data from discovery
     * @return array Validation results
     */
    public function validateDriver(array $driver): array
    {
        $errors = [];
        $warnings = [];

        try {
            // Check driver info completeness
            $info = $driver['info'];
            if (empty($info->name)) {
                $errors[] = 'Driver name is required';
            }

            if (empty($info->version)) {
                $warnings[] = 'Driver version not specified';
            }

            // Validate dependencies
            $missingDeps = $info->validateDependencies();
            if (!empty($missingDeps)) {
                $errors = array_merge($errors, $missingDeps);
            }

            // Test driver instantiation
            $className = $driver['class'];
            $reflection = $driver['reflection'];

            if ($reflection->getConstructor() && $reflection->getConstructor()->getNumberOfRequiredParameters() > 0) {
                $warnings[] = 'Driver constructor requires parameters - may need configuration';
            }
        } catch (\Exception $e) {
            $errors[] = 'Driver validation failed: ' . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}
