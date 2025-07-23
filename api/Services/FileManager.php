<?php

declare(strict_types=1);

namespace Glueful\Services;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * File Manager Service
 *
 * Provides safe, atomic file operations using Symfony Filesystem component.
 * Wraps Symfony Filesystem with additional security, logging, and error handling.
 */
class FileManager
{
    private Filesystem $filesystem;
    private ?LoggerInterface $logger;
    private array $config;

    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $this->filesystem = new Filesystem();
        $this->logger = $logger;
        $this->config = array_merge([
            'default_mode' => 0755,
            'enable_logging' => true,
            'max_path_length' => 4096,
            'allowed_extensions' => null, // null = allow all
            'forbidden_paths' => ['/etc', '/usr', '/var/log', '/sys', '/proc'],
        ], $config);
    }

    /**
     * Write content to file atomically
     *
     * @param string $path File path
     * @param string $content Content to write
     * @param int|null $mode File permissions (default: 0644)
     * @return bool True if successful
     * @throws RuntimeException If write operation fails
     */
    public function writeFile(string $path, string $content, ?int $mode = null): bool
    {
        $this->validatePath($path);

        try {
            $this->filesystem->dumpFile($path, $content);

            if ($mode !== null) {
                $this->filesystem->chmod($path, $mode);
            }

            $this->log('info', "File written successfully: {$path}");
            return true;
        } catch (IOExceptionInterface $e) {
            $this->log('error', "Failed to write file {$path}: " . $e->getMessage());
            throw new RuntimeException("Failed to write file: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Read file content safely
     *
     * @param string $path File path
     * @return string File content
     * @throws RuntimeException If file cannot be read
     */
    public function readFile(string $path): string
    {
        $this->validatePath($path);

        if (!$this->filesystem->exists($path)) {
            throw new RuntimeException("File does not exist: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $content;
    }

    /**
     * Create directory with proper permissions
     *
     * @param string $path Directory path
     * @param int|null $mode Directory permissions
     * @return bool True if successful
     * @throws RuntimeException If directory creation fails
     */
    public function createDirectory(string $path, ?int $mode = null): bool
    {
        $this->validatePath($path);
        $mode = $mode ?? $this->config['default_mode'];

        try {
            $this->filesystem->mkdir($path, $mode);
            $this->log('info', "Directory created: {$path}");
            return true;
        } catch (IOExceptionInterface $e) {
            $this->log('error', "Failed to create directory {$path}: " . $e->getMessage());
            throw new RuntimeException("Failed to create directory: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Remove file or directory safely
     *
     * @param string $path Path to remove
     * @return bool True if successful
     * @throws RuntimeException If removal fails
     */
    public function remove(string $path): bool
    {
        $this->validatePath($path);

        if (!$this->filesystem->exists($path)) {
            return true; // Already doesn't exist
        }

        try {
            $this->filesystem->remove($path);
            $this->log('info', "Removed: {$path}");
            return true;
        } catch (IOExceptionInterface $e) {
            $this->log('error', "Failed to remove {$path}: " . $e->getMessage());
            throw new RuntimeException("Failed to remove: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Copy file or directory
     *
     * @param string $source Source path
     * @param string $target Target path
     * @param bool $overwrite Whether to overwrite existing files
     * @return bool True if successful
     * @throws RuntimeException If copy operation fails
     */
    public function copy(string $source, string $target, bool $overwrite = false): bool
    {
        $this->validatePath($source);
        $this->validatePath($target);

        if (!$this->filesystem->exists($source)) {
            throw new RuntimeException("Source does not exist: {$source}");
        }

        if (!$overwrite && $this->filesystem->exists($target)) {
            throw new RuntimeException("Target already exists: {$target}");
        }

        try {
            if (is_dir($source)) {
                $this->filesystem->mirror($source, $target, null, ['override' => $overwrite]);
            } else {
                $this->filesystem->copy($source, $target, $overwrite);
            }

            $this->log('info', "Copied {$source} to {$target}");
            return true;
        } catch (IOExceptionInterface $e) {
            $this->log('error', "Failed to copy {$source} to {$target}: " . $e->getMessage());
            throw new RuntimeException("Failed to copy: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Move/rename file or directory
     *
     * @param string $source Source path
     * @param string $target Target path
     * @param bool $overwrite Whether to overwrite existing files
     * @return bool True if successful
     * @throws RuntimeException If move operation fails
     */
    public function move(string $source, string $target, bool $overwrite = false): bool
    {
        $this->validatePath($source);
        $this->validatePath($target);

        if (!$this->filesystem->exists($source)) {
            throw new RuntimeException("Source does not exist: {$source}");
        }

        if (!$overwrite && $this->filesystem->exists($target)) {
            throw new RuntimeException("Target already exists: {$target}");
        }

        try {
            $this->filesystem->rename($source, $target, $overwrite);
            $this->log('info', "Moved {$source} to {$target}");
            return true;
        } catch (IOExceptionInterface $e) {
            $this->log('error', "Failed to move {$source} to {$target}: " . $e->getMessage());
            throw new RuntimeException("Failed to move: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if path exists
     *
     * @param string $path Path to check
     * @return bool True if exists
     */
    public function exists(string $path): bool
    {
        return $this->filesystem->exists($path);
    }

    /**
     * Make path relative to another path
     *
     * @param string $endPath Absolute path
     * @param string $startPath Base path
     * @return string Relative path
     */
    public function makePathRelative(string $endPath, string $startPath): string
    {
        return $this->filesystem->makePathRelative($endPath, $startPath);
    }

    /**
     * Change file/directory permissions
     *
     * @param string $path Path to modify
     * @param int $mode New permissions
     * @param int $umask Umask to apply
     * @param bool $recursive Apply recursively to directories
     * @return bool True if successful
     * @throws RuntimeException If chmod operation fails
     */
    public function chmod(string $path, int $mode, int $umask = 0000, bool $recursive = false): bool
    {
        $this->validatePath($path);

        try {
            $this->filesystem->chmod($path, $mode, $umask, $recursive);
            $this->log('info', "Changed permissions for {$path} to " . decoct($mode));
            return true;
        } catch (IOExceptionInterface $e) {
            $this->log('error', "Failed to chmod {$path}: " . $e->getMessage());
            throw new RuntimeException("Failed to change permissions: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create temporary file
     *
     * @param string $prefix Filename prefix
     * @param string $suffix Filename suffix
     * @param string|null $directory Directory (null = system temp)
     * @return string Path to temporary file
     * @throws RuntimeException If temp file creation fails
     */
    public function createTempFile(
        string $prefix = 'glueful_',
        string $suffix = '.tmp',
        ?string $directory = null
    ): string {
        $directory = $directory ?? sys_get_temp_dir();
        $this->validatePath($directory);

        try {
            $tempFile = $this->filesystem->tempnam($directory, $prefix, $suffix);
            $this->log('info', "Created temporary file: {$tempFile}");
            return $tempFile;
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException("Failed to create temporary file: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get file size
     *
     * @param string $path File path
     * @return int File size in bytes
     * @throws RuntimeException If file doesn't exist or size cannot be determined
     */
    public function getFileSize(string $path): int
    {
        $this->validatePath($path);

        if (!$this->filesystem->exists($path)) {
            throw new RuntimeException("File does not exist: {$path}");
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Cannot determine file size: {$path}");
        }

        return $size;
    }

    /**
     * Validate file path for security
     *
     * @param string $path Path to validate
     * @throws InvalidArgumentException If path is invalid or unsafe
     */
    private function validatePath(string $path): void
    {
        // Check path length
        if (strlen($path) > $this->config['max_path_length']) {
            throw new InvalidArgumentException("Path too long: {$path}");
        }

        // Check for null bytes (security)
        if (strpos($path, "\0") !== false) {
            throw new InvalidArgumentException("Path contains null byte: {$path}");
        }

        // Check forbidden paths
        $realPath = realpath(dirname($path)) ?: dirname($path);
        foreach ($this->config['forbidden_paths'] as $forbidden) {
            if (strpos($realPath, $forbidden) === 0) {
                throw new InvalidArgumentException("Access to forbidden path: {$path}");
            }
        }

        // Check file extension if restrictions are configured
        if ($this->config['allowed_extensions'] !== null) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->config['allowed_extensions'], true)) {
                throw new InvalidArgumentException("File extension not allowed: {$extension}");
            }
        }
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
            $this->logger->log($level, $message, ['service' => 'FileManager']);
        }
    }

    /**
     * Get underlying Symfony Filesystem instance
     *
     * @return Filesystem
     */
    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
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
