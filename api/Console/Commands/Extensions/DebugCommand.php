<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extensions Debug Command
 * - Comprehensive extension system debugging information
 * - Autoload path analysis and verification
 * - Class loading diagnostics
 * - Memory usage and performance insights
 * - Extension state and configuration debugging
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:debug',
    description: 'Show debug information about extension system'
)]
class DebugCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Show debug information about extension system')
             ->setHelp(
                 'This command provides comprehensive debugging information about the extension system ' .
                 'and individual extensions.'
             )
             ->addOption(
                 'verbose',
                 'v',
                 InputOption::VALUE_NONE,
                 'Show detailed debug information'
             )
             ->addOption(
                 'errors-only',
                 'e',
                 InputOption::VALUE_NONE,
                 'Show only errors and warnings'
             )
             ->addOption(
                 'extension',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Debug specific extension'
             )
             ->addOption(
                 'memory',
                 'm',
                 InputOption::VALUE_NONE,
                 'Include memory usage information'
             )
             ->addOption(
                 'autoload',
                 'a',
                 InputOption::VALUE_NONE,
                 'Focus on autoload debugging'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $errorsOnly = $input->getOption('errors-only');
        $specificExtension = $input->getOption('extension');
        $includeMemory = $input->getOption('memory');
        $focusAutoload = $input->getOption('autoload');

        try {
            if (!$errorsOnly) {
                $this->info('Extension System Debug Information');
                $this->line('==================================');
            }

            $debugInfo = [];

            // System overview
            if (!$errorsOnly) {
                $debugInfo['system'] = $this->getSystemDebugInfo($includeMemory);
            }

            // Extension discovery and loading
            $debugInfo['discovery'] = $this->getDiscoveryDebugInfo();

            // Autoload information
            if ($focusAutoload || $verbose) {
                $debugInfo['autoload'] = $this->getAutoloadDebugInfo();
            }

            // Individual extension debugging
            if ($specificExtension) {
                $debugInfo['extension'] = $this->getExtensionDebugInfo($specificExtension, $verbose);
            } elseif ($verbose) {
                $debugInfo['extensions'] = $this->getAllExtensionsDebugInfo();
            }

            // Configuration debugging
            $debugInfo['configuration'] = $this->getConfigurationDebugInfo();

            // Performance debugging
            if ($verbose || $includeMemory) {
                $debugInfo['performance'] = $this->getPerformanceDebugInfo();
            }

            // Display debug information
            $this->displayDebugInfo($debugInfo, $errorsOnly, $verbose);

            if (!$errorsOnly) {
                $this->line('');
                $this->success('Debug information collection completed!');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Debug information collection failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function getSystemDebugInfo(bool $includeMemory): array
    {
        $info = [
            'php_version' => PHP_VERSION,
            'extensions_dir' => dirname(__DIR__, 6) . '/extensions',
            'extensions_dir_exists' => is_dir(dirname(__DIR__, 6) . '/extensions'),
            'extensions_dir_readable' => is_readable(dirname(__DIR__, 6) . '/extensions'),
            'extensions_dir_writable' => is_writable(dirname(__DIR__, 6) . '/extensions'),
        ];

        if ($includeMemory) {
            $info['memory_usage'] = memory_get_usage(true);
            $info['memory_peak'] = memory_get_peak_usage(true);
            $info['memory_limit'] = ini_get('memory_limit');
        }

        return $info;
    }

    private function getDiscoveryDebugInfo(): array
    {
        $info = [
            'discovered_extensions' => [],
            'failed_discoveries' => [],
            'discovery_errors' => []
        ];

        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            $info['discovery_errors'][] = 'Extensions directory does not exist';
            return $info;
        }

        $directories = scandir($extensionsDir);
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $extensionPath = "{$extensionsDir}/{$dir}";

            if (!is_dir($extensionPath)) {
                continue;
            }

            $configFile = "{$extensionPath}/extension.json";

            if (!file_exists($configFile)) {
                $info['failed_discoveries'][] = [
                    'name' => $dir,
                    'reason' => 'Missing extension.json'
                ];
                continue;
            }

            $configContent = file_get_contents($configFile);
            $config = json_decode($configContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $info['failed_discoveries'][] = [
                    'name' => $dir,
                    'reason' => 'Invalid JSON: ' . json_last_error_msg()
                ];
                continue;
            }

            $info['discovered_extensions'][] = [
                'name' => $dir,
                'config_name' => $config['name'] ?? 'unknown',
                'version' => $config['version'] ?? 'unknown',
                'enabled' => $config['enabled'] ?? false,
                'has_autoload' => isset($config['autoload']),
                'dependencies_count' => count($config['dependencies'] ?? [])
            ];
        }

        return $info;
    }

    private function getAutoloadDebugInfo(): array
    {
        $info = [
            'autoload_mappings' => [],
            'path_issues' => [],
            'namespace_conflicts' => []
        ];

        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            return $info;
        }

        $namespaceMap = [];
        $directories = scandir($extensionsDir);

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                continue;
            }

            $configFile = "{$extensionsDir}/{$dir}/extension.json";
            if (!file_exists($configFile)) {
                continue;
            }

            $config = json_decode(file_get_contents($configFile), true);
            if (!$config) {
                continue;
            }

            $autoload = $config['autoload']['psr-4'] ?? [];

            foreach ($autoload as $namespace => $path) {
                $fullPath = "{$extensionsDir}/{$dir}/{$path}";

                $mapping = [
                    'extension' => $dir,
                    'namespace' => $namespace,
                    'path' => $path,
                    'full_path' => $fullPath,
                    'path_exists' => is_dir($fullPath),
                    'path_readable' => is_readable($fullPath),
                    'php_files_count' => is_dir($fullPath) ? count(glob("{$fullPath}/*.php")) : 0
                ];

                $info['autoload_mappings'][] = $mapping;

                if (!$mapping['path_exists']) {
                    $info['path_issues'][] = [
                        'extension' => $dir,
                        'namespace' => $namespace,
                        'issue' => 'Path does not exist',
                        'path' => $fullPath
                    ];
                }

                // Check for namespace conflicts
                if (!isset($namespaceMap[$namespace])) {
                    $namespaceMap[$namespace] = [];
                }
                $namespaceMap[$namespace][] = $dir;
            }
        }

        // Detect conflicts
        foreach ($namespaceMap as $namespace => $extensions) {
            if (count($extensions) > 1) {
                $info['namespace_conflicts'][] = [
                    'namespace' => $namespace,
                    'extensions' => $extensions
                ];
            }
        }

        return $info;
    }

    private function getExtensionDebugInfo(string $extensionName, bool $verbose): array
    {
        $extensionPath = dirname(__DIR__, 6) . "/extensions/{$extensionName}";

        $info = [
            'name' => $extensionName,
            'path' => $extensionPath,
            'exists' => is_dir($extensionPath),
        ];

        if (!$info['exists']) {
            $info['error'] = 'Extension directory does not exist';
            return $info;
        }

        $configFile = "{$extensionPath}/extension.json";
        $info['config_file_exists'] = file_exists($configFile);

        if (!$info['config_file_exists']) {
            $info['error'] = 'extension.json not found';
            return $info;
        }

        $configContent = file_get_contents($configFile);
        $config = json_decode($configContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $info['error'] = 'Invalid JSON: ' . json_last_error_msg();
            return $info;
        }

        $info['config'] = $config;
        $info['enabled'] = $config['enabled'] ?? false;
        $info['version'] = $config['version'] ?? 'unknown';
        $info['description'] = $config['description'] ?? '';

        // Check autoload paths
        $autoload = $config['autoload']['psr-4'] ?? [];
        $info['autoload_paths'] = [];

        foreach ($autoload as $namespace => $path) {
            $fullPath = "{$extensionPath}/{$path}";
            $info['autoload_paths'][] = [
                'namespace' => $namespace,
                'path' => $path,
                'full_path' => $fullPath,
                'exists' => is_dir($fullPath),
                'readable' => is_readable($fullPath),
                'php_files' => is_dir($fullPath) ? glob("{$fullPath}/*.php") : []
            ];
        }

        // Check dependencies
        $dependencies = $config['dependencies'] ?? [];
        $info['dependencies'] = [];

        foreach ($dependencies as $depName => $version) {
            $depPath = dirname(__DIR__, 6) . "/extensions/{$depName}";
            $info['dependencies'][] = [
                'name' => $depName,
                'required_version' => $version,
                'exists' => is_dir($depPath),
                'available' => is_dir($depPath) && file_exists("{$depPath}/extension.json")
            ];
        }

        if ($verbose) {
            // Additional verbose information
            $info['file_structure'] = $this->getFileStructure($extensionPath);
            $info['size_info'] = $this->getDirectorySize($extensionPath);
        }

        return $info;
    }

    private function getAllExtensionsDebugInfo(): array
    {
        $extensions = [];
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            return $extensions;
        }

        $directories = scandir($extensionsDir);
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                continue;
            }

            $extensions[] = $this->getExtensionDebugInfo($dir, false);
        }

        return $extensions;
    }

    private function getConfigurationDebugInfo(): array
    {
        $info = [
            'global_config_issues' => [],
            'extension_config_issues' => []
        ];

        // Check global configuration
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            $info['global_config_issues'][] = 'Extensions directory missing';
        } elseif (!is_readable($extensionsDir)) {
            $info['global_config_issues'][] = 'Extensions directory not readable';
        }

        // Check individual extension configs
        if (is_dir($extensionsDir)) {
            $directories = scandir($extensionsDir);
            foreach ($directories as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                    continue;
                }

                $configFile = "{$extensionsDir}/{$dir}/extension.json";
                if (!file_exists($configFile)) {
                    $info['extension_config_issues'][] = [
                        'extension' => $dir,
                        'issue' => 'Missing extension.json'
                    ];
                    continue;
                }

                $config = json_decode(file_get_contents($configFile), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $info['extension_config_issues'][] = [
                        'extension' => $dir,
                        'issue' => 'Invalid JSON: ' . json_last_error_msg()
                    ];
                }
            }
        }

        return $info;
    }

    private function getPerformanceDebugInfo(): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Simulate extension operations
        $this->simulateExtensionOperations();

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        return [
            'operation_time' => ($endTime - $startTime) * 1000, // milliseconds
            'memory_used' => $endMemory - $startMemory,
            'current_memory' => $endMemory,
            'peak_memory' => memory_get_peak_usage(),
            'memory_limit' => ini_get('memory_limit')
        ];
    }

    private function simulateExtensionOperations(): void
    {
        // Simulate typical extension operations for performance measurement
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (is_dir($extensionsDir)) {
            $directories = scandir($extensionsDir);
            foreach ($directories as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                    continue;
                }

                $configFile = "{$extensionsDir}/{$dir}/extension.json";
                if (file_exists($configFile)) {
                    json_decode(file_get_contents($configFile), true);
                }
            }
        }
    }

    private function getFileStructure(string $path): array
    {
        $structure = [];

        if (!is_dir($path)) {
            return $structure;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = "{$path}/{$item}";
            if (is_dir($itemPath)) {
                $structure['directories'][] = $item;
            } else {
                $structure['files'][] = $item;
            }
        }

        return $structure;
    }

    private function getDirectorySize(string $path): array
    {
        $size = 0;
        $fileCount = 0;

        if (!is_dir($path)) {
            return ['size' => 0, 'file_count' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
            $fileCount++;
        }

        return [
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'file_count' => $fileCount
        ];
    }

    private function displayDebugInfo(array $debugInfo, bool $errorsOnly, bool $verbose): void
    {
        if (!$errorsOnly && isset($debugInfo['system'])) {
            $this->displaySystemInfo($debugInfo['system']);
        }

        $this->displayDiscoveryInfo($debugInfo['discovery'], $errorsOnly);

        if (isset($debugInfo['autoload'])) {
            $this->displayAutoloadInfo($debugInfo['autoload'], $errorsOnly);
        }

        if (isset($debugInfo['extension'])) {
            $this->displayExtensionInfo($debugInfo['extension'], $verbose);
        }

        if (isset($debugInfo['extensions']) && $verbose) {
            $this->displayAllExtensionsInfo($debugInfo['extensions']);
        }

        $this->displayConfigurationInfo($debugInfo['configuration'], $errorsOnly);

        if (isset($debugInfo['performance'])) {
            $this->displayPerformanceInfo($debugInfo['performance']);
        }
    }

    private function displaySystemInfo(array $info): void
    {
        $this->line('');
        $this->info('System Information:');

        $rows = [
            ['PHP Version', $info['php_version']],
            ['Extensions Directory', $info['extensions_dir']],
            ['Directory Exists', $info['extensions_dir_exists'] ? 'Yes' : 'No'],
            ['Directory Readable', $info['extensions_dir_readable'] ? 'Yes' : 'No'],
            ['Directory Writable', $info['extensions_dir_writable'] ? 'Yes' : 'No']
        ];

        if (isset($info['memory_usage'])) {
            $rows[] = ['Memory Usage', $this->formatBytes($info['memory_usage'])];
            $rows[] = ['Peak Memory', $this->formatBytes($info['memory_peak'])];
            $rows[] = ['Memory Limit', $info['memory_limit']];
        }

        $this->table(['Property', 'Value'], $rows);
    }

    private function displayDiscoveryInfo(array $info, bool $errorsOnly): void
    {
        if (!$errorsOnly) {
            $this->line('');
            $this->info('Extension Discovery:');
            $this->line("Found " . count($info['discovered_extensions']) . " valid extensions");
        }

        if (!empty($info['failed_discoveries'])) {
            $this->line('');
            $this->error('Discovery Issues:');
            foreach ($info['failed_discoveries'] as $failure) {
                $this->line("  • {$failure['name']}: {$failure['reason']}");
            }
        }

        if (!empty($info['discovery_errors'])) {
            $this->line('');
            $this->error('Discovery Errors:');
            foreach ($info['discovery_errors'] as $error) {
                $this->line("  • {$error}");
            }
        }
    }

    private function displayAutoloadInfo(array $info, bool $errorsOnly): void
    {
        if (!$errorsOnly) {
            $this->line('');
            $this->info('Autoload Information:');
            $this->line("Total mappings: " . count($info['autoload_mappings']));
        }

        if (!empty($info['path_issues'])) {
            $this->line('');
            $this->error('Autoload Path Issues:');
            foreach ($info['path_issues'] as $issue) {
                $this->line("  • {$issue['extension']}: {$issue['issue']} ({$issue['path']})");
            }
        }

        if (!empty($info['namespace_conflicts'])) {
            $this->line('');
            $this->error('Namespace Conflicts:');
            foreach ($info['namespace_conflicts'] as $conflict) {
                $extensions = implode(', ', $conflict['extensions']);
                $this->line("  • {$conflict['namespace']}: {$extensions}");
            }
        }
    }

    private function displayExtensionInfo(array $info, bool $verbose): void
    {
        $this->line('');
        $this->info("Extension Debug: {$info['name']}");

        if (isset($info['error'])) {
            $this->error("Error: {$info['error']}");
            return;
        }

        $rows = [
            ['Path', $info['path']],
            ['Exists', $info['exists'] ? 'Yes' : 'No'],
            ['Config File', $info['config_file_exists'] ? 'Yes' : 'No'],
            ['Enabled', $info['enabled'] ? 'Yes' : 'No'],
            ['Version', $info['version']],
        ];

        $this->table(['Property', 'Value'], $rows);

        if ($verbose && isset($info['file_structure'])) {
            $this->line('');
            $this->info('File Structure:');
            $this->line('Directories: ' . implode(', ', $info['file_structure']['directories'] ?? []));
            $this->line('Files: ' . implode(', ', $info['file_structure']['files'] ?? []));
        }
    }

    private function displayAllExtensionsInfo(array $extensions): void
    {
        $this->line('');
        $this->info('All Extensions Debug Summary:');

        $rows = [];
        foreach ($extensions as $ext) {
            $status = isset($ext['error']) ? 'Error' : ($ext['enabled'] ? 'Enabled' : 'Disabled');
            $rows[] = [
                $ext['name'],
                $ext['version'] ?? 'unknown',
                $status,
                $ext['exists'] ? 'Yes' : 'No'
            ];
        }

        $this->table(['Extension', 'Version', 'Status', 'Exists'], $rows);
    }

    private function displayConfigurationInfo(array $info, bool $errorsOnly): void
    {
        if (!empty($info['global_config_issues']) || !empty($info['extension_config_issues'])) {
            $this->line('');
            $this->error('Configuration Issues:');

            foreach ($info['global_config_issues'] as $issue) {
                $this->line("  • Global: {$issue}");
            }

            foreach ($info['extension_config_issues'] as $issue) {
                $this->line("  • {$issue['extension']}: {$issue['issue']}");
            }
        } elseif (!$errorsOnly) {
            $this->line('');
            $this->line('<info>✓ No configuration issues detected</info>');
        }
    }

    private function displayPerformanceInfo(array $info): void
    {
        $this->line('');
        $this->info('Performance Debug:');

        $rows = [
            ['Operation Time', number_format($info['operation_time'], 2) . 'ms'],
            ['Memory Used', $this->formatBytes($info['memory_used'])],
            ['Current Memory', $this->formatBytes($info['current_memory'])],
            ['Peak Memory', $this->formatBytes($info['peak_memory'])],
            ['Memory Limit', $info['memory_limit']]
        ];

        $this->table(['Metric', 'Value'], $rows);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 1024;

        for ($i = 0; $i < count($units) && $bytes >= $factor; $i++) {
            $bytes /= $factor;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
