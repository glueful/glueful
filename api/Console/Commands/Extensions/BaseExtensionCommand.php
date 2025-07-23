<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\ExtensionManager;
use Glueful\Services\FileFinder;
use Glueful\Services\FileManager;

/**
 * Base Extension Command
 * Base class for all Extension commands providing common functionality:
 * - Extension lookup and validation helpers
 * - Common data structure handling
 * - Shared utility methods for extension management
 * - Consistent error handling and user feedback
 * @package Glueful\Console\Commands\Extensions
 */
abstract class BaseExtensionCommand extends BaseCommand
{
    /** @var FileFinder|null */
    protected ?FileFinder $fileFinder = null;

    /** @var FileManager|null */
    protected ?FileManager $fileManager = null;
    /**
     * Find an extension by name from installed extensions
     *
     * @param ExtensionManager $manager ExtensionManager instance
     * @param string $extensionName Extension name to find
     * @return array|null Extension data or null if not found
     */
    protected function findExtension(ExtensionManager $manager, string $extensionName): ?array
    {
        if (!$manager->isInstalled($extensionName)) {
            return null;
        }

        $metadata = $manager->getExtensionMetadata($extensionName);
        if (!$metadata) {
            return null;
        }

        // Merge metadata with extension state info for backward compatibility
        $extensionData = array_merge($metadata, [
            'name' => $extensionName,
            'enabled' => $manager->isEnabled($extensionName),
            'type' => $manager->isCoreExtension($extensionName) ? 'core' : 'optional'
        ]);

        // Also keep the nested structure for compatibility
        $extensionData['metadata'] = $extensionData;

        return $extensionData;
    }

    /**
     * Get all extensions as a keyed array by extension name
     *
     * @param ExtensionManager $manager ExtensionManager instance
     * @return array Extensions keyed by name
     */
    protected function getExtensionsKeyed(ExtensionManager $manager): array
    {
        $installedExtensions = $manager->listInstalled();
        $keyed = [];

        foreach ($installedExtensions as $extensionData) {
            $extensionName = $extensionData['name'];
            $extension = $this->findExtension($manager, $extensionName);
            if ($extension) {
                $keyed[$extensionName] = $extension;
            }
        }

        return $keyed;
    }

    /**
     * Check if an extension exists
     *
     * @param ExtensionManager $manager ExtensionManager instance
     * @param string $extensionName Extension name to check
     * @return bool True if extension exists
     */
    protected function extensionExists(ExtensionManager $manager, string $extensionName): bool
    {
        return $manager->isInstalled($extensionName);
    }

    /**
     * Check if an extension is enabled
     *
     * @param ExtensionManager $manager ExtensionManager instance
     * @param string $extensionName Extension name to check
     * @return bool True if extension is enabled
     */
    protected function isExtensionEnabled(ExtensionManager $manager, string $extensionName): bool
    {
        return $manager->isEnabled($extensionName);
    }

    /**
     * Get extension dependencies
     *
     * @param array $extension Extension data
     * @return array Array of dependency names
     */
    protected function getExtensionDependencies(array $extension): array
    {
        return $extension['metadata']['dependencies']['extensions'] ?? [];
    }

    /**
     * Find extensions that depend on the given extension
     *
     * @param ExtensionManager $manager ExtensionManager instance
     * @param string $extensionName Extension name to check dependents for
     * @return array Array of dependent extension names
     */
    protected function findDependentExtensions(ExtensionManager $manager, string $extensionName): array
    {
        $installedExtensions = $manager->listInstalled();
        $dependents = [];

        foreach ($installedExtensions as $extensionData) {
            $installedExtensionName = $extensionData['name'];
            $extension = $this->findExtension($manager, $installedExtensionName);
            if ($extension) {
                $dependencies = $this->getExtensionDependencies($extension);
                if (in_array($extensionName, $dependencies)) {
                    $dependents[] = $extension['name'];
                }
            }
        }

        return $dependents;
    }

    /**
     * Find similar extension names for suggestions
     *
     * @param string $input Input extension name
     * @param array $available Array of available extension names
     * @return array Array of similar extension names
     */
    protected function findSimilarExtensions(string $input, array $available): array
    {
        $suggestions = [];
        $input = strtolower($input);

        foreach ($available as $extension) {
            $similarity = 0;
            similar_text($input, strtolower($extension), $similarity);

            if ($similarity > 60) {
                $suggestions[] = $extension;
            }
        }

        return array_slice($suggestions, 0, 3); // Return top 3 suggestions
    }

    /**
     * Display extension suggestions when extension not found
     *
     * @param string $input Input extension name
     * @param array $available Array of available extension names
     * @return void
     */
    protected function suggestSimilarExtensions(string $input, array $available): void
    {
        $suggestions = $this->findSimilarExtensions($input, $available);

        if (!empty($suggestions)) {
            $this->line('');
            $this->info('Did you mean:');
            foreach ($suggestions as $suggestion) {
                $this->line("• {$suggestion}");
            }
        } else {
            $this->line('');
            $this->info('Available extensions:');
            foreach ($available as $extension) {
                $this->line("• {$extension}");
            }
        }
    }

    /**
     * Format extension status for display
     *
     * @param bool $enabled Whether extension is enabled
     * @return string Formatted status string
     */
    protected function formatExtensionStatus(bool $enabled): string
    {
        return $enabled ? '<info>✓ Enabled</info>' : '<comment>• Disabled</comment>';
    }

    /**
     * Format extension type for display
     *
     * @param string $type Extension type (core or optional)
     * @return string Formatted type string
     */
    protected function formatExtensionType(string $type): string
    {
        return $type === 'core' ? '<comment>Core</comment>' : 'Optional';
    }

    /**
     * Validate extension name format
     *
     * @param string $name Extension name to validate
     * @return bool True if name is valid
     */
    protected function isValidExtensionName(string $name): bool
    {
        if (empty($name)) {
            return false;
        }

        return preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name) === 1;
    }

    /**
     * Display validation error for extension name
     *
     * @param string $name Invalid extension name
     * @return void
     */
    protected function displayExtensionNameError(string $name): void
    {
        $this->error("Invalid extension name: {$name}");
        $this->info('Extension names must:');
        $this->line('• Start with a capital letter');
        $this->line('• Contain only letters and numbers');
        $this->line('• Use PascalCase format');
        $this->tip('Examples: MyExtension, ApiHelper, UserManager');
    }

    /**
     * Get extension metadata safely
     *
     * @param array $extension Extension data
     * @param string $key Metadata key
     * @param mixed $default Default value if key not found
     * @return mixed Metadata value or default
     */
    protected function getExtensionMetadata(array $extension, string $key, $default = null)
    {
        return $extension['metadata'][$key] ?? $default;
    }

    /**
     * Get FileFinder service instance
     *
     * @return FileFinder
     */
    protected function getFileFinder(): FileFinder
    {
        if ($this->fileFinder === null) {
            $this->fileFinder = $this->getService(FileFinder::class);
        }
        return $this->fileFinder;
    }

    /**
     * Get FileManager service instance
     *
     * @return FileManager
     */
    protected function getFileManager(): FileManager
    {
        if ($this->fileManager === null) {
            $this->fileManager = $this->getService(FileManager::class);
        }
        return $this->fileManager;
    }

    /**
     * Check if extension directory exists using FileManager
     *
     * @param string $extensionName Extension name
     * @return bool True if directory exists
     */
    protected function extensionDirectoryExists(string $extensionName): bool
    {
        $extensionPath = $this->getExtensionPath($extensionName);
        return $this->getFileManager()->exists($extensionPath) && is_dir($extensionPath);
    }

    /**
     * Get extension directory path
     *
     * @param string $extensionName Extension name
     * @return string Extension directory path
     */
    protected function getExtensionPath(string $extensionName): string
    {
        return dirname(__DIR__, 4) . "/extensions/{$extensionName}";
    }

    /**
     * Get extension config file path
     *
     * @param string $extensionName Extension name
     * @return string Config file path
     */
    protected function getExtensionConfigPath(string $extensionName): string
    {
        return $this->getExtensionPath($extensionName) . '/manifest.json';
    }

    /**
     * Load extension configuration using FileManager
     *
     * @param string $extensionName Extension name
     * @return array|null Extension config or null if not found/invalid
     */
    protected function loadExtensionConfig(string $extensionName): ?array
    {
        $configPath = $this->getExtensionConfigPath($extensionName);
        if (!$this->getFileManager()->exists($configPath)) {
            return null;
        }

        try {
            $content = $this->getFileManager()->readFile($configPath);
            $config = json_decode($content, true);
            return is_array($config) ? $config : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get extensions directory path
     *
     * @return string Extensions directory path
     */
    protected function getExtensionsDirectory(): string
    {
        return dirname(__DIR__, 4) . '/extensions';
    }

    /**
     * Find all extension directories using FileFinder
     *
     * @return array Array of extension directory names
     */
    protected function findExtensionDirectories(): array
    {
        $extensionsDir = $this->getExtensionsDirectory();
        if (!$this->getFileManager()->exists($extensionsDir) || !is_dir($extensionsDir)) {
            return [];
        }

        $directories = $this->getFileFinder()->findDirectories($extensionsDir);

        // Convert iterator to array and return only directory names
        $result = [];
        foreach ($directories as $directory) {
            $dirName = $directory->getFilename();
            if ($dirName !== '.' && $dirName !== '..') {
                $result[] = $dirName;
            }
        }
        return $result;
    }

    /**
     * Find PHP files in extension using FileFinder
     *
     * @param string $extensionName Extension name
     * @param string $subdirectory Optional subdirectory (e.g., 'src')
     * @return array Array of PHP file paths
     */
    protected function findExtensionPhpFiles(string $extensionName, string $subdirectory = ''): array
    {
        $searchPath = $this->getExtensionPath($extensionName);
        if ($subdirectory) {
            $searchPath .= '/' . ltrim($subdirectory, '/');
        }

        if (!$this->getFileManager()->exists($searchPath) || !is_dir($searchPath)) {
            return [];
        }

        $files = $this->getFileFinder()->findPhpFiles($searchPath);

        // Convert iterator to array of file paths
        $result = [];
        foreach ($files as $file) {
            $result[] = $file->getRealPath();
        }
        return $result;
    }

    /**
     * Format file size for display
     *
     * @param int $bytes File size in bytes
     * @return string Formatted size string
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 1024;

        for ($i = 0; $i < count($units) && $bytes >= $factor; $i++) {
            $bytes /= $factor;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }
}
