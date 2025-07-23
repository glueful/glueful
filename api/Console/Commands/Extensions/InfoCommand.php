<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unified Extensions Info Command
 * Comprehensive extension information command that provides:
 * - Extension listing with filtering and formatting options
 * - Detailed information about specific extensions
 * - PSR-4 namespaces and autoload mappings
 * Usage Examples:
 * - php glueful extensions:info                           # List all extensions
 * - php glueful extensions:info ExtensionName             # Detailed info for specific extension
 * - php glueful extensions:info --namespaces              # Show all namespaces
 * - php glueful extensions:info ExtensionName --namespaces # Show namespaces for specific extension
 * - php glueful extensions:info --status=enabled --format=json # List enabled extensions in JSON
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:info',
    description: 'Display extension information, listings, and namespace mappings'
)]
class InfoCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Display extension information, listings, and namespace mappings')
             ->setHelp(
                 'This command provides comprehensive extension information. ' .
                 'Without arguments, lists all extensions. With a name, shows detailed info for that extension. ' .
                 'Use --namespaces to focus on PSR-4 namespace mappings.'
             )
             ->addArgument(
                 'name',
                 InputArgument::OPTIONAL,
                 'Extension name for detailed information (lists all if omitted)'
             )
             // Listing options (from ListCommand)
             ->addOption(
                 'status',
                 's',
                 InputOption::VALUE_REQUIRED,
                 'Filter by extension status (enabled, disabled, all)',
                 'all'
             )
             ->addOption(
                 'format',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, compact)',
                 'table'
             )
             ->addOption(
                 'show-autoload',
                 'a',
                 InputOption::VALUE_NONE,
                 'Show autoload information for extensions'
             )
             ->addOption(
                 'show-dependencies',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show extension dependencies and requirements'
             )
             // Namespace options (from NamespacesCommand)
             ->addOption(
                 'namespaces',
                 'nm',
                 InputOption::VALUE_NONE,
                 'Focus on namespace and autoload mappings'
             )
             ->addOption(
                 'conflicts',
                 'c',
                 InputOption::VALUE_NONE,
                 'Check for namespace conflicts'
             )
             ->addOption(
                 'performance',
                 'p',
                 InputOption::VALUE_NONE,
                 'Show performance metrics for namespace resolution'
             )
             ->addOption(
                 'detailed',
                 null,
                 InputOption::VALUE_NONE,
                 'Show detailed information (applies to both extension info and namespaces)'
             )
             ->addOption(
                 'filter',
                 'f',
                 InputOption::VALUE_REQUIRED,
                 'Filter namespaces by pattern (only with --namespaces)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        $status = $input->getOption('status');
        $format = $input->getOption('format');
        $showAutoload = $input->getOption('show-autoload');
        $showDependencies = $input->getOption('show-dependencies');
        $namespaceFocus = $input->getOption('namespaces');
        $checkConflicts = $input->getOption('conflicts');
        $showPerformance = $input->getOption('performance');
        $detailed = $input->getOption('detailed');
        $filter = $input->getOption('filter');

        // Validate options
        if (!in_array($status, ['enabled', 'disabled', 'all'])) {
            $this->error("Invalid status filter: {$status}. Use: enabled, disabled, or all");
            return self::FAILURE;
        }

        if (!in_array($format, ['table', 'json', 'compact'])) {
            $this->error("Invalid format: {$format}. Use: table, json, or compact");
            return self::FAILURE;
        }

        try {
            $extensionsManager = $this->getService(ExtensionManager::class);

            if ($namespaceFocus) {
                // Namespace-focused mode
                return $this->executeNamespaceMode(
                    $extensionsManager,
                    $extensionName,
                    $checkConflicts,
                    $showPerformance,
                    $detailed,
                    $filter
                );
            } elseif ($extensionName) {
                // Detailed info for specific extension
                return $this->executeDetailedMode($extensionsManager, $extensionName);
            } else {
                // List all extensions
                return $this->executeListMode(
                    $extensionsManager,
                    $status,
                    $format,
                    $showAutoload,
                    $showDependencies
                );
            }
        } catch (\Exception $e) {
            $this->error("Failed to get extension information: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function executeListMode(
        ExtensionManager $manager,
        string $status,
        string $format,
        bool $showAutoload,
        bool $showDependencies
    ): int {
        $this->info('Loading extension information...');

        $extensions = $this->getFilteredExtensions($manager, $status);

        if (empty($extensions)) {
            $this->warning("No extensions found with status: {$status}");
            $this->displayInstallationTip();
            return self::SUCCESS;
        }

        $this->displayExtensions($extensions, $format, $showAutoload, $showDependencies);
        $this->displaySummary($extensions);

        return self::SUCCESS;
    }

    private function executeDetailedMode(ExtensionManager $manager, string $extensionName): int
    {
        $extension = $this->findExtension($manager, $extensionName);

        if (!$extension) {
            $this->error("Extension '{$extensionName}' not found.");
            $extensions = $manager->listInstalled();
            $available = $extensions;
            $this->suggestSimilarExtensions($extensionName, $available);
            return self::FAILURE;
        }

        $this->displayExtensionInfo($extensionName, $extension, $manager);
        return self::SUCCESS;
    }

    private function executeNamespaceMode(
        ExtensionManager $manager,
        ?string $extensionName,
        bool $checkConflicts,
        bool $showPerformance,
        bool $detailed,
        ?string $filter
    ): int {
        if ($extensionName) {
            $this->info("Extension Namespaces: {$extensionName}");
        } else {
            $this->info('Extension Namespaces');
        }
        $this->line('===================');

        // Get namespaces
        $namespaces = $this->collectNamespaces($manager, $extensionName);

        if (empty($namespaces)) {
            if ($extensionName) {
                $this->warning("No namespaces found for extension: {$extensionName}");
            } else {
                $this->warning('No extension namespaces found');
            }
            return self::SUCCESS;
        }

        // Apply filter if provided
        if ($filter) {
            $namespaces = $this->filterNamespaces($namespaces, $filter);
            $this->info("Filtered namespaces (pattern: {$filter}):");
        }

        // Display namespaces
        $this->displayNamespaces($namespaces, $detailed);

        // Check for conflicts if requested
        if ($checkConflicts && !$extensionName) {
            $this->checkNamespaceConflicts($namespaces);
        }

        // Show performance metrics if requested
        if ($showPerformance) {
            $this->showPerformanceMetrics($namespaces);
        }

        // Display summary
        $this->displayNamespaceSummary($namespaces);

        return self::SUCCESS;
    }

    private function getFilteredExtensions(ExtensionManager $manager, string $status): array
    {
        $allExtensions = $this->getExtensionsKeyed($manager);

        if ($status === 'all') {
            return $allExtensions;
        }

        return array_filter($allExtensions, function ($extension) use ($status) {
            $isEnabled = $extension['metadata']['enabled'] ?? false;
            return ($status === 'enabled' && $isEnabled) || ($status === 'disabled' && !$isEnabled);
        });
    }

    private function displayExtensions(
        array $extensions,
        string $format,
        bool $showAutoload,
        bool $showDependencies
    ): void {
        switch ($format) {
            case 'json':
                $this->displayJsonFormat($extensions);
                break;
            case 'compact':
                $this->displayCompactFormat($extensions);
                break;
            default:
                $this->displayTableFormat($extensions, $showAutoload, $showDependencies);
        }
    }

    private function displayTableFormat(array $extensions, bool $showAutoload, bool $showDependencies): void
    {
        $headers = ['Name', 'Status', 'Version', 'Description'];

        if ($showAutoload) {
            $headers[] = 'Autoload';
        }

        if ($showDependencies) {
            $headers[] = 'Dependencies';
        }

        $rows = [];
        foreach ($extensions as $extension) {
            $name = $extension['name'];
            $metadata = $extension['metadata'];
            $status = $metadata['enabled'] ? '<info>✓ Enabled</info>' : '<comment>• Disabled</comment>';
            $version = $metadata['version'] ?? 'Unknown';
            $description = $this->truncateText($metadata['description'] ?? 'No description', 50);

            $row = [$name, $status, $version, $description];

            if ($showAutoload) {
                $autoload = $metadata['autoload'] ?? 'None';
                $row[] = is_array($autoload) ? implode(', ', array_keys($autoload)) : $autoload;
            }

            if ($showDependencies) {
                $deps = $metadata['dependencies']['extensions'] ?? [];
                $row[] = empty($deps) ? 'None' : implode(', ', $deps);
            }

            $rows[] = $row;
        }

        $this->table($headers, $rows);
    }

    private function displayJsonFormat(array $extensions): void
    {
        $this->line(json_encode($extensions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function displayCompactFormat(array $extensions): void
    {
        foreach ($extensions as $extension) {
            $name = $extension['name'];
            $metadata = $extension['metadata'];
            $status = $metadata['enabled'] ? '✓' : '•';
            $version = $metadata['version'] ?? 'Unknown';
            $this->line("{$status} {$name} ({$version})");
        }
    }

    private function displaySummary(array $extensions): void
    {
        $enabled = array_filter($extensions, fn($ext) => $ext['metadata']['enabled'] ?? false);
        $disabled = array_filter($extensions, fn($ext) => !($ext['metadata']['enabled'] ?? false));

        $this->line('');
        $this->info('Summary:');
        $this->table(['Metric', 'Count'], [
            ['Total Extensions', count($extensions)],
            ['Enabled', count($enabled)],
            ['Disabled', count($disabled)],
        ]);
    }

    private function displayInstallationTip(): void
    {
        $this->line('');
        $this->info('To install extensions:');
        $this->line('• Use: extensions:create <name> to create a new extension');
        $this->line('• Use: extensions:install <url-or-file> to install from archive');
        $this->line('• Place extensions in the extensions/ directory');
    }

    private function displayExtensionInfo(string $name, array $extension, ExtensionManager $manager): void
    {
        $this->info("Extension Information: {$name}");
        $this->line('');

        // Basic Information
        $this->displayBasicInfo($name, $extension);

        // Dependencies
        $this->displayDependencies($extension, $manager);

        // Dependents
        $this->displayDependents($name, $manager);

        // Files and Structure
        $this->displayFileInfo($name, $extension);

        // Configuration
        $this->displayConfiguration($extension);

        // Namespace Information
        $this->displayExtensionNamespaces($name, $extension);
    }

    private function displayBasicInfo(string $name, array $extension): void
    {
        $status = $extension['enabled'] ? '<info>✓ Enabled</info>' : '<comment>• Disabled</comment>';

        $basicInfo = [
            ['Name', $name],
            ['Status', $status],
            ['Version', $extension['version'] ?? 'Unknown'],
            ['Description', is_array($extension['description'] ?? '') ?
                json_encode($extension['description']) : ($extension['description'] ?? 'No description')],
            ['Author', $this->formatAuthor($extension['author'] ?? 'Unknown')],
            ['License', $extension['license'] ?? 'Unknown'],
        ];

        if (isset($extension['homepage'])) {
            $basicInfo[] = ['Homepage', $extension['homepage']];
        }

        $this->table(['Property', 'Value'], $basicInfo);
    }

    private function displayDependencies(array $extension, ExtensionManager $manager): void
    {
        $this->line('');
        $this->info('Dependencies:');

        $dependencies = $extension['dependencies']['extensions'] ?? [];

        if (empty($dependencies)) {
            $this->line('  None');
            return;
        }

        $depRows = [];
        foreach ($dependencies as $depName) {
            $depExtension = $this->findExtension($manager, $depName);
            $depStatus = 'Not Found';
            if ($depExtension) {
                $depStatus = $depExtension['metadata']['enabled'] ?
                    '<info>✓ Enabled</info>' : '<comment>• Disabled</comment>';
            }
            $depRows[] = [$depName, 'Latest', $depStatus];
        }

        $this->table(['Dependency', 'Required Version', 'Status'], $depRows);
    }

    private function displayDependents(string $name, ExtensionManager $manager): void
    {
        $this->line('');
        $this->info('Dependent Extensions:');

        $dependents = [];
        $allExtensions = $this->getExtensionsKeyed($manager);
        foreach ($allExtensions as $extension) {
            $deps = $extension['metadata']['dependencies']['extensions'] ?? [];
            if (in_array($name, $deps)) {
                $dependents[] = [
                    $extension['name'],
                    $extension['metadata']['enabled'] ? '<info>✓ Enabled</info>' : '<comment>• Disabled</comment>'
                ];
            }
        }

        if (empty($dependents)) {
            $this->line('  None');
            return;
        }

        $this->table(['Extension', 'Status'], $dependents);
    }

    private function displayFileInfo(string $name, array $extension): void
    {
        $this->line('');
        $this->info('File Information:');

        $extensionPath = $this->getExtensionPath($name);
        $configFile = $this->getExtensionConfigPath($name);

        $fileInfo = [
            ['Extension Directory', $extensionPath],
            ['Directory Exists', $this->extensionDirectoryExists($name) ? '<info>✓ Yes</info>' : '<error>✗ No</error>'],
            ['Config File', $configFile],
            [
                'Config Exists',
                $this->getFileManager()->exists($configFile) ? '<info>✓ Yes</info>' : '<error>✗ No</error>'
            ],
        ];

        if (isset($extension['autoload'])) {
            $autoload = $extension['autoload'];
            if (is_array($autoload)) {
                $autoloadInfo = implode(', ', array_keys($autoload));
            } else {
                $autoloadInfo = (string) $autoload;
            }
            $fileInfo[] = ['Autoload', $autoloadInfo];
        }

        $this->table(['Property', 'Value'], $fileInfo);
    }

    private function displayConfiguration(array $extension): void
    {
        $this->line('');
        $this->info('Configuration:');

        $config = $extension['config'] ?? [];

        if (empty($config)) {
            $this->line('  No configuration options defined');
            return;
        }

        $configRows = [];
        foreach ($config as $key => $value) {
            $displayValue = is_array($value) ? json_encode($value) : (string) $value;
            $configRows[] = [$key, $displayValue];
        }

        $this->table(['Option', 'Value'], $configRows);
    }

    private function displayExtensionNamespaces(string $name, array $extension): void
    {
        $this->line('');
        $this->info('Namespaces:');

        // Try to get autoload from extension config (extensions.json)
        $autoload = [];
        // First check if it's in the extension metadata
        if (isset($extension['autoload']['psr-4'])) {
            $autoload = $extension['autoload']['psr-4'];
        } else {
            // Get from extension configuration via ExtensionConfig service
            try {
                $configService = $this->getService(
                    \Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface::class
                );
                $config = $configService->getExtensionConfig($name);
                $autoload = $config['autoload']['psr-4'] ?? [];
            } catch (\Exception) {
                // Fall back to empty if we can't get the config
                $autoload = [];
            }
        }

        if (empty($autoload)) {
            $this->line('  No PSR-4 namespaces defined');
            return;
        }

        $namespaceRows = [];
        foreach ($autoload as $namespace => $path) {
            // Path is relative to project root, not extension directory
            $projectRoot = dirname(__DIR__, 4); // Go up from api/Console/Commands/Extensions
            $fullPath = $projectRoot . '/' . $path;
            $pathExists = is_dir($fullPath) ? '<info>✓ Exists</info>' : '<error>✗ Missing</error>';
            $namespaceRows[] = [$namespace, $path, $pathExists];
        }

        $this->table(['Namespace', 'Path', 'Status'], $namespaceRows);
    }

    private function collectNamespaces(ExtensionManager $manager, ?string $extensionName = null): array
    {
        $namespaces = [];

        try {
            $extensions = $this->getExtensionsKeyed($manager);

            foreach ($extensions as $extension) {
                // Filter by specific extension if provided
                if ($extensionName && $extension['name'] !== $extensionName) {
                    continue;
                }

                $metadata = $extension['metadata'] ?? [];
                $autoload = $metadata['autoload']['psr-4'] ?? [];

                foreach ($autoload as $namespace => $path) {
                    $namespaces[] = [
                        'namespace' => $namespace,
                        'path' => $path,
                        'extension' => $extension['name'],
                        'enabled' => $metadata['enabled'] ?? false,
                        'type' => $metadata['type'] ?? 'optional',
                        'version' => $metadata['version'] ?? 'unknown'
                    ];
                }
            }
        } catch (\Exception $exception) {
            // Fallback: scan extensions directory directly
            $this->warning('Using fallback namespace detection...');
            $namespaces = $this->scanExtensionsDirectory($extensionName);
            unset($exception); // Exception logging could be added here
        }

        return $namespaces;
    }

    private function scanExtensionsDirectory(?string $extensionName = null): array
    {
        $namespaces = [];
        $extensionsDir = dirname(__DIR__, 6) . '/extensions';

        if (!is_dir($extensionsDir)) {
            return $namespaces;
        }

        $directories = scandir($extensionsDir);
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir("{$extensionsDir}/{$dir}")) {
                continue;
            }

            // Filter by specific extension if provided
            if ($extensionName && $dir !== $extensionName) {
                continue;
            }

            $configFile = "{$extensionsDir}/{$dir}/manifest.json";
            if (!file_exists($configFile)) {
                continue;
            }

            $config = json_decode(file_get_contents($configFile), true);
            if (!$config) {
                continue;
            }

            $autoload = $config['autoload']['psr-4'] ?? [];
            foreach ($autoload as $namespace => $path) {
                $namespaces[] = [
                    'namespace' => $namespace,
                    'path' => $path,
                    'extension' => $dir,
                    'enabled' => $config['enabled'] ?? false,
                    'type' => $config['type'] ?? 'optional',
                    'version' => $config['version'] ?? 'unknown'
                ];
            }
        }

        return $namespaces;
    }

    private function filterNamespaces(array $namespaces, string $pattern): array
    {
        return array_filter($namespaces, function ($namespace) use ($pattern) {
            return stripos($namespace['namespace'], $pattern) !== false ||
                   stripos($namespace['extension'], $pattern) !== false;
        });
    }

    private function displayNamespaces(array $namespaces, bool $detailed): void
    {
        if ($detailed) {
            $this->displayDetailedNamespaces($namespaces);
        } else {
            $this->displayCompactNamespaces($namespaces);
        }
    }

    private function displayCompactNamespaces(array $namespaces): void
    {
        $this->line('');

        $rows = [];
        foreach ($namespaces as $ns) {
            $status = $ns['enabled'] ? '<info>✓</info>' : '<comment>•</comment>';
            $type = $ns['type'] === 'core' ? '<comment>Core</comment>' : 'Optional';

            $rows[] = [
                $ns['namespace'],
                $ns['extension'],
                $status,
                $type,
                $ns['version']
            ];
        }

        $this->table(['Namespace', 'Extension', 'Status', 'Type', 'Version'], $rows);
    }

    private function displayDetailedNamespaces(array $namespaces): void
    {
        foreach ($namespaces as $index => $ns) {
            if ($index > 0) {
                $this->line('');
            }

            $status = $ns['enabled'] ? '<info>Enabled</info>' : '<comment>Disabled</comment>';
            $type = $ns['type'] === 'core' ? '<comment>Core</comment>' : 'Optional';

            $this->info("Namespace: {$ns['namespace']}");
            $this->line("  Extension: {$ns['extension']}");
            $this->line("  Path: {$ns['path']}");
            $this->line("  Status: {$status}");
            $this->line("  Type: {$type}");
            $this->line("  Version: {$ns['version']}");

            // Check if path exists
            $extensionPath = dirname(__DIR__, 6) . "/extensions/{$ns['extension']}/{$ns['path']}";
            $pathExists = is_dir($extensionPath) ? '<info>✓ Exists</info>' : '<error>✗ Missing</error>';
            $this->line("  Path Status: {$pathExists}");

            // Show PSR-4 compliance
            $psr4Compliant = $this->checkPSR4Compliance($ns['namespace'], $extensionPath);
            $complianceStatus = $psr4Compliant ? '<info>✓ PSR-4 Compliant</info>' : '<comment>⚠ Check PSR-4</comment>';
            $this->line("  PSR-4: {$complianceStatus}");
        }
    }

    private function checkPSR4Compliance(string $namespace, string $path): bool
    {
        unset($namespace); // Namespace parsing could be added here for stricter validation

        if (!is_dir($path)) {
            return false;
        }

        // Check if there are PHP files in the path
        $files = glob("{$path}/*.php");
        if (empty($files)) {
            return true; // No files to check
        }

        // For a more complete check, we'd need to parse PHP files
        // For now, just check if the path exists
        return true;
    }

    private function checkNamespaceConflicts(array $namespaces): void
    {
        $this->line('');
        $this->info('Namespace Conflict Analysis:');
        $this->line('============================');

        $conflicts = [];
        $namespaceMap = [];

        // Group by namespace
        foreach ($namespaces as $ns) {
            $namespace = $ns['namespace'];
            if (!isset($namespaceMap[$namespace])) {
                $namespaceMap[$namespace] = [];
            }
            $namespaceMap[$namespace][] = $ns;
        }

        // Find conflicts
        foreach ($namespaceMap as $namespace => $extensions) {
            if (count($extensions) > 1) {
                $conflicts[] = [
                    'namespace' => $namespace,
                    'extensions' => $extensions
                ];
            }
        }

        if (empty($conflicts)) {
            $this->line('<info>✓ No namespace conflicts detected</info>');
            return;
        }

        $this->error("Found " . count($conflicts) . " namespace conflict(s):");

        foreach ($conflicts as $conflict) {
            $this->line('');
            $this->line("<error>Conflict in namespace: {$conflict['namespace']}</error>");

            foreach ($conflict['extensions'] as $ext) {
                $status = $ext['enabled'] ? 'enabled' : 'disabled';
                $this->line("  • {$ext['extension']} ({$status})");
            }

            $this->tip('Only one extension should use this namespace to avoid conflicts.');
        }
    }

    private function showPerformanceMetrics(array $namespaces): void
    {
        $this->line('');
        $this->info('Performance Metrics:');
        $this->line('===================');

        $startTime = microtime(true);

        // Simulate namespace resolution performance test
        $resolvedCount = 0;
        $failedCount = 0;

        foreach ($namespaces as $ns) {
            $extensionPath = dirname(__DIR__, 6) . "/extensions/{$ns['extension']}/{$ns['path']}";

            if (is_dir($extensionPath)) {
                $resolvedCount++;

                // Check for autoload performance (simulate class loading)
                $files = glob("{$extensionPath}/*.php");
                foreach (array_slice($files, 0, 3) as $file) {
                    // Simulate file access time
                    $fileAccessible = file_exists($file);
                    unset($fileAccessible);
                }
            } else {
                $failedCount++;
            }
        }

        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);

        $this->table(['Metric', 'Value'], [
            ['Total Namespaces', count($namespaces)],
            ['Resolved Paths', $resolvedCount],
            ['Failed Paths', $failedCount],
            ['Resolution Time', "{$duration}ms"],
            ['Average per Namespace', round($duration / count($namespaces), 2) . 'ms'],
            ['Success Rate', round(($resolvedCount / count($namespaces)) * 100, 1) . '%']
        ]);

        if ($failedCount > 0) {
            $this->warning("Performance may be affected by {$failedCount} unresolved namespace path(s)");
        }
    }

    private function displayNamespaceSummary(array $namespaces): void
    {
        $this->line('');
        $this->info('Summary:');

        $enabledCount = count(array_filter($namespaces, fn($ns) => $ns['enabled']));
        $coreCount = count(array_filter($namespaces, fn($ns) => $ns['type'] === 'core'));
        $uniqueExtensions = count(array_unique(array_column($namespaces, 'extension')));

        $summary = [
            ['Total Namespaces', count($namespaces)],
            ['Enabled Extensions', $enabledCount],
            ['Core Extensions', $coreCount],
            ['Unique Extensions', $uniqueExtensions],
            ['Average Namespaces per Extension', round(count($namespaces) / max($uniqueExtensions, 1), 1)]
        ];

        $this->table(['Metric', 'Value'], $summary);

        if ($enabledCount === 0) {
            $this->warning('No extensions are currently enabled');
        }
    }

    private function truncateText(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length - 3) . '...' : $text;
    }

    /**
     * Format author information for display
     *
     * @param string|array $author Author data
     * @return string Formatted author string
     */
    private function formatAuthor($author): string
    {
        if (is_array($author)) {
            $name = $author['name'] ?? 'Unknown';
            $email = $author['email'] ?? null;
            $url = $author['url'] ?? null;
            $formatted = $name;
            if ($email) {
                $formatted .= " <{$email}>";
            }
            if ($url) {
                $formatted .= " ({$url})";
            }
            return $formatted;
        }
        return (string) $author;
    }
}
