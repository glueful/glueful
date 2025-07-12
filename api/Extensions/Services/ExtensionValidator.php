<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services;

use Glueful\Extensions\Services\Interfaces\ExtensionValidatorInterface;
use Glueful\Extensions\Exceptions\ExtensionException;
use Glueful\Services\FileManager;
use Glueful\DI\ContainerBootstrap;
use Psr\Log\LoggerInterface;

class ExtensionValidator implements ExtensionValidatorInterface
{
    private bool $debug = false;
    private array $frameworkVersion;
    private array $securityPatterns;

    public function __construct(
        private ?FileManager $fileManager = null,
        private ?LoggerInterface $logger = null
    ) {
        $this->initializeServices();
        $this->initializeSecurityPatterns();
        $this->frameworkVersion = $this->parseVersion($this->getFrameworkVersion());
    }

    private function initializeServices(): void
    {
        if ($this->fileManager === null || $this->logger === null) {
            try {
                $container = ContainerBootstrap::getContainer();
                $this->fileManager ??= $container->get(FileManager::class);
                $this->logger ??= $container->get(LoggerInterface::class);
            } catch (\Exception $e) {
                // Fallback to creating directly if container not available
                $this->fileManager ??= new FileManager();
            }
        }
    }

    public function setDebugMode(bool $enable = true): void
    {
        $this->debug = $enable;
    }

    public function validateExtension(string $path): array
    {
        $results = [
            'valid' => false,
            'issues' => [],
            'warnings' => [],
            'security_issues' => [],
            'dependency_issues' => [],
            'structure_valid' => false,
            'syntax_valid' => false
        ];

        // Validate structure
        $structureResult = $this->validateStructure($path);
        $results['structure_valid'] = empty($structureResult);
        if (!empty($structureResult)) {
            $results['issues'] = array_merge($results['issues'], $structureResult);
        }

        // Load and validate manifest
        $manifest = $this->loadManifest($path);
        if ($manifest) {
            $manifestIssues = $this->validateManifest($manifest);
            if (!empty($manifestIssues)) {
                $results['issues'] = array_merge($results['issues'], $manifestIssues);
            }

            // Validate dependencies
            if (isset($manifest['engines'])) {
                $dependencyResult = $this->validateDependencies($manifest['engines']);
                if (!$dependencyResult) {
                    $results['dependency_issues'][] = 'Framework version compatibility check failed';
                }
            }
        }

        // Validate file permissions
        if (!$this->validatePermissions($path)) {
            $results['issues'][] = 'Invalid file permissions detected';
        }

        // Validate syntax
        $syntaxIssues = $this->validateSyntax($path);
        $results['syntax_valid'] = empty($syntaxIssues);
        if (!empty($syntaxIssues)) {
            $results['issues'] = array_merge($results['issues'], $syntaxIssues);
        }

        // Security validation
        $securityIssues = $this->validateSecurity($path);
        $results['security_issues'] = $securityIssues;
        if (!empty($securityIssues)) {
            $results['issues'] = array_merge($results['issues'], $securityIssues);
        }

        // Overall validation
        $results['valid'] = empty($results['issues']) && empty($results['security_issues']);

        $this->debugLog("Validation completed for {$path}. Valid: " . ($results['valid'] ? 'Yes' : 'No'));

        return $results;
    }

    public function validateDependencies(array $dependencies): bool
    {
        foreach ($dependencies as $dependency => $constraint) {
            if ($dependency === 'glueful') {
                if (!$this->checkVersionConstraint($this->frameworkVersion, $constraint)) {
                    $this->debugLog("Framework version constraint failed: {$constraint}");
                    return false;
                }
            } elseif ($dependency === 'php') {
                $phpVersion = $this->parseVersion(PHP_VERSION);
                if (!$this->checkVersionConstraint($phpVersion, $constraint)) {
                    $this->debugLog("PHP version constraint failed: {$constraint}");
                    return false;
                }
            }
        }

        return true;
    }

    public function validateSecurity(string $path): array
    {
        $issues = [];

        // Scan PHP files for security issues
        $phpFiles = $this->findPhpFiles($path);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Check for dangerous functions
            foreach ($this->securityPatterns['dangerous_functions'] as $function) {
                if (preg_match('/\b' . preg_quote($function) . '\s*\(/', $content)) {
                    $issues[] = "Potentially dangerous function '{$function}' found in {$file}";
                }
            }

            // Check for SQL injection patterns
            foreach ($this->securityPatterns['sql_injection'] as $pattern) {
                if (preg_match($pattern, $content)) {
                    $issues[] = "Potential SQL injection vulnerability found in {$file}";
                }
            }

            // Check for XSS patterns
            foreach ($this->securityPatterns['xss'] as $pattern) {
                if (preg_match($pattern, $content)) {
                    $issues[] = "Potential XSS vulnerability found in {$file}";
                }
            }

            // Check for file inclusion vulnerabilities (only actual dangerous patterns)
            if (preg_match('/\b(?:include|require)(?:_once)?\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/', $content)) {
                $issues[] = "Potential file inclusion vulnerability found in {$file}";
            }
        }

        return $issues;
    }

    public function checkCompatibility(array $metadata): bool
    {
        // Check framework compatibility
        if (isset($metadata['engines']['glueful'])) {
            $constraint = $metadata['engines']['glueful'];
            if (!$this->checkVersionConstraint($this->frameworkVersion, $constraint)) {
                return false;
            }
        }

        // Check PHP compatibility
        if (isset($metadata['engines']['php'])) {
            $constraint = $metadata['engines']['php'];
            $phpVersion = $this->parseVersion(PHP_VERSION);
            if (!$this->checkVersionConstraint($phpVersion, $constraint)) {
                return false;
            }
        }

        return true;
    }

    public function validateManifest(array $manifest): array
    {
        $issues = [];

        // Required fields
        $requiredFields = ['manifestVersion', 'id', 'name', 'version', 'main'];
        foreach ($requiredFields as $field) {
            if (!isset($manifest[$field]) || empty($manifest[$field])) {
                $issues[] = "Required field '{$field}' is missing or empty";
            }
        }

        // Validate manifest version
        if (isset($manifest['manifestVersion'])) {
            if (!in_array($manifest['manifestVersion'], ['1.0', '2.0'])) {
                $issues[] = "Unsupported manifest version: {$manifest['manifestVersion']}";
            }
        }

        // Validate version format
        if (isset($manifest['version'])) {
            if (!preg_match('/^\d+\.\d+\.\d+/', $manifest['version'])) {
                $issues[] = "Invalid version format: {$manifest['version']}";
            }
        }

        // Validate ID format
        if (isset($manifest['id'])) {
            if (!preg_match('/^[a-z][a-z0-9-]*[a-z0-9]$/', $manifest['id'])) {
                $issues[] = "Invalid ID format: {$manifest['id']}";
            }
        }

        // Validate name format
        if (isset($manifest['name'])) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $manifest['name'])) {
                $issues[] = "Invalid name format: {$manifest['name']}";
            }
        }

        return $issues;
    }

    public function validateFiles(string $path, array $manifest): bool
    {
        // Check if main file exists
        $mainFile = $manifest['main'] ?? null;
        if ($mainFile && !file_exists($path . '/' . $mainFile)) {
            return false;
        }

        // Check if required directories exist
        $requiredDirs = ['src'];
        foreach ($requiredDirs as $dir) {
            if (!is_dir($path . '/' . $dir)) {
                // Not required for all extensions
                $this->debugLog("Optional directory missing: {$dir}");
            }
        }

        return true;
    }

    public function validateSyntax(string $path): array
    {
        $issues = [];
        $phpFiles = $this->findPhpFiles($path);

        foreach ($phpFiles as $file) {
            $output = [];
            $returnVar = 0;

            // Use php -l to check syntax
            exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnVar);

            if ($returnVar !== 0) {
                $issues[] = "Syntax error in {$file}: " . implode(' ', $output);
            }
        }

        return $issues;
    }

    public function checkNameConflicts(string $name): bool
    {
        // Check if extension with same name already exists
        $extensionsPath = $this->getExtensionsPath();
        $extensionPath = $extensionsPath . '/' . $name;

        return !is_dir($extensionPath);
    }

    public function validatePermissions(string $path): bool
    {
        // Check if path is readable
        if (!is_readable($path)) {
            return false;
        }

        // Check if files have appropriate permissions
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $perms = $file->getPerms();

                // Check for overly permissive permissions
                if (($perms & 0002) || ($perms & 0020)) {
                    $this->debugLog("Overly permissive permissions on: {$file->getPathname()}");
                    return false;
                }
            }
        }

        return true;
    }

    private function validateStructure(string $path): array
    {
        $issues = [];

        // Check if path exists and is directory
        if (!is_dir($path)) {
            $issues[] = "Extension path does not exist or is not a directory";
            return $issues;
        }

        // Check for manifest.json
        if (!file_exists($path . '/manifest.json')) {
            $issues[] = "manifest.json file is missing";
        }

        return $issues;
    }

    private function loadManifest(string $path): ?array
    {
        $manifestPath = $path . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return null;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        $manifest = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? $manifest : null;
    }

    private function findPhpFiles(string $path): array
    {
        $phpFiles = [];

        if (!is_dir($path)) {
            return $phpFiles;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }

        return $phpFiles;
    }

    private function parseVersion(string $version): array
    {
        // Remove any pre-release or build metadata
        $version = preg_replace('/[-+].*$/', '', $version);
        $parts = explode('.', $version);

        return [
            'major' => (int) ($parts[0] ?? 0),
            'minor' => (int) ($parts[1] ?? 0),
            'patch' => (int) ($parts[2] ?? 0)
        ];
    }

    private function checkVersionConstraint(array $version, string $constraint): bool
    {
        // Simple constraint checking - supports >= for now
        if (str_starts_with($constraint, '>=')) {
            $requiredVersion = $this->parseVersion(substr($constraint, 2));

            if ($version['major'] > $requiredVersion['major']) {
                return true;
            }

            if ($version['major'] === $requiredVersion['major']) {
                if ($version['minor'] > $requiredVersion['minor']) {
                    return true;
                }

                if ($version['minor'] === $requiredVersion['minor']) {
                    return $version['patch'] >= $requiredVersion['patch'];
                }
            }

            return false;
        }

        // Default to true for unsupported constraints
        return true;
    }

    private function getFrameworkVersion(): string
    {
        // This would typically come from a framework constant or config
        return '0.27.0'; // Current Glueful version
    }

    private function getExtensionsPath(): string
    {
        // Go up from api/Extensions/Services to the project root
        $projectRoot = dirname(__DIR__, 3); // Up 3 levels from api/Extensions/Services/
        return $projectRoot . '/extensions';
    }

    private function initializeSecurityPatterns(): void
    {
        $this->securityPatterns = [
            'dangerous_functions' => [
                // Only include truly dangerous functions, not legitimate file operations
                'eval', 'exec', 'system', 'shell_exec', 'passthru'
                // Removed file operations like file_get_contents, fopen etc. as they're commonly needed
                // Removed filesystem operations like unlink, mkdir etc. as extensions may need them
            ],
            'sql_injection' => [
                '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\.\s*[\'"]/',
                '/(?:SELECT|INSERT|UPDATE|DELETE).*\$_(?:GET|POST|REQUEST|COOKIE)/',
                '/mysql_query.*\$_(?:GET|POST|REQUEST|COOKIE)/'
            ],
            'xss' => [
                '/echo\s+\$_(?:GET|POST|REQUEST|COOKIE)/',
                '/print\s+\$_(?:GET|POST|REQUEST|COOKIE)/',
                '/(?:innerHTML|outerHTML)\s*=.*\$_(?:GET|POST|REQUEST|COOKIE)/'
            ]
        ];
    }

    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($this->logger) {
            $this->logger->debug("[ExtensionValidator] {$message}");
        } else {
            error_log("[ExtensionValidator] {$message}");
        }
    }
}
