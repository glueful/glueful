<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\ExtensionConstraintRegistry;
use Glueful\DI\Interfaces\ContainerInterface;

/**
 * Validation Extension Loader
 *
 * Integrates with the Glueful extension system to automatically discover
 * and register validation constraints from extensions.
 */
class ValidationExtensionLoader
{
    /** @var ExtensionConstraintRegistry Constraint registry */
    private ExtensionConstraintRegistry $registry;

    /** @var ContainerInterface DI container */
    private ContainerInterface $container;

    /** @var array<string, int> Loaded extensions tracking */
    private array $loadedExtensions = [];

    /**
     * Constructor
     *
     * @param ExtensionConstraintRegistry $registry Constraint registry
     * @param ContainerInterface $container DI container
     */
    public function __construct(
        ExtensionConstraintRegistry $registry,
        ContainerInterface $container
    ) {
        $this->registry = $registry;
        $this->container = $container;
    }

    /**
     * Load validation constraints from an extension
     *
     * @param string $extensionName Extension name
     * @param string $extensionPath Extension path
     * @return array Load result with statistics
     */
    public function loadExtensionConstraints(string $extensionName, string $extensionPath): array
    {
        $startTime = microtime(true);

        // Check if already loaded
        if (isset($this->loadedExtensions[$extensionName])) {
            return [
                'success' => true,
                'extension' => $extensionName,
                'constraints_loaded' => 0,
                'already_loaded' => true,
                'load_time' => 0,
            ];
        }

        try {
            // Discover constraints from extension
            $constraintsLoaded = $this->registry->discoverConstraintsFromExtension(
                $extensionPath,
                $extensionName
            );

            // Register extension validators in DI container
            $this->registerExtensionValidators($extensionName, $extensionPath);

            // Mark as loaded
            $this->loadedExtensions[$extensionName] = $constraintsLoaded;

            $loadTime = microtime(true) - $startTime;

            return [
                'success' => true,
                'extension' => $extensionName,
                'constraints_loaded' => $constraintsLoaded,
                'already_loaded' => false,
                'load_time' => $loadTime,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'extension' => $extensionName,
                'error' => $e->getMessage(),
                'load_time' => microtime(true) - $startTime,
            ];
        }
    }

    /**
     * Load constraints from all enabled extensions
     *
     * @param array<string, string> $extensions Extension name => path mapping
     * @return array Load results for all extensions
     */
    public function loadAllExtensionConstraints(array $extensions): array
    {
        $results = [];
        $totalConstraints = 0;
        $totalTime = 0;

        foreach ($extensions as $extensionName => $extensionPath) {
            $result = $this->loadExtensionConstraints($extensionName, $extensionPath);
            $results[$extensionName] = $result;

            if ($result['success']) {
                $totalConstraints += $result['constraints_loaded'];
            }

            $totalTime += $result['load_time'];
        }

        return [
            'extensions' => $results,
            'summary' => [
                'total_extensions' => count($extensions),
                'successful_loads' => count(array_filter($results, fn($r) => $r['success'])),
                'total_constraints' => $totalConstraints,
                'total_load_time' => $totalTime,
            ],
        ];
    }

    /**
     * Unload constraints from an extension
     *
     * @param string $extensionName Extension name
     * @return bool True if unloaded successfully
     */
    public function unloadExtensionConstraints(string $extensionName): bool
    {
        if (!isset($this->loadedExtensions[$extensionName])) {
            return false;
        }

        // Get constraints to unload
        $constraints = $this->registry->getConstraintsByExtension($extensionName);

        // Unregister each constraint
        foreach ($constraints as $constraintClass => $info) {
            $this->registry->unregisterConstraint($constraintClass);
        }

        // Remove from loaded extensions
        unset($this->loadedExtensions[$extensionName]);

        return true;
    }

    /**
     * Get loading statistics
     *
     * @return array Loading statistics
     */
    public function getLoadingStatistics(): array
    {
        return [
            'loaded_extensions' => count($this->loadedExtensions),
            'extensions' => $this->loadedExtensions,
            'registry_stats' => $this->registry->getStatistics(),
        ];
    }

    /**
     * Check if extension constraints are loaded
     *
     * @param string $extensionName Extension name
     * @return bool True if extension constraints are loaded
     */
    public function isExtensionLoaded(string $extensionName): bool
    {
        return isset($this->loadedExtensions[$extensionName]);
    }

    /**
     * Get loaded extension names
     *
     * @return array<string> Loaded extension names
     */
    public function getLoadedExtensions(): array
    {
        return array_keys($this->loadedExtensions);
    }

    /**
     * Reload extension constraints
     *
     * @param string $extensionName Extension name
     * @param string $extensionPath Extension path
     * @return array Reload result
     */
    public function reloadExtensionConstraints(string $extensionName, string $extensionPath): array
    {
        // Unload first
        $this->unloadExtensionConstraints($extensionName);

        // Load again
        return $this->loadExtensionConstraints($extensionName, $extensionPath);
    }

    /**
     * Validate extension structure for constraints
     *
     * @param string $extensionPath Extension path
     * @return array Validation result
     */
    public function validateExtensionStructure(string $extensionPath): array
    {
        $constraintPath = $extensionPath . '/src/Validation/Constraints';
        $validatorPath = $extensionPath . '/src/Validation/ConstraintValidators';

        $issues = [];

        // Check if constraint directory exists
        if (!is_dir($constraintPath)) {
            return [
                'valid' => true,
                'has_constraints' => false,
                'issues' => [],
            ];
        }

        // Check if validator directory exists
        if (!is_dir($validatorPath)) {
            $issues[] = "Validator directory missing: {$validatorPath}";
        }

        // Check constraint files
        $constraintFiles = glob($constraintPath . '/*.php') ?: [];
        $validatorFiles = glob($validatorPath . '/*.php') ?: [];

        foreach ($constraintFiles as $constraintFile) {
            $constraintName = pathinfo($constraintFile, PATHINFO_FILENAME);
            $expectedValidatorFile = $validatorPath . '/' . $constraintName . 'Validator.php';

            if (!file_exists($expectedValidatorFile)) {
                $issues[] = "Missing validator for constraint: {$constraintName}";
            }
        }

        // Check for orphaned validators
        foreach ($validatorFiles as $validatorFile) {
            $validatorName = pathinfo($validatorFile, PATHINFO_FILENAME);
            if (str_ends_with($validatorName, 'Validator')) {
                $constraintName = substr($validatorName, 0, -9); // Remove 'Validator' suffix
                $expectedConstraintFile = $constraintPath . '/' . $constraintName . '.php';

                if (!file_exists($expectedConstraintFile)) {
                    $issues[] = "Orphaned validator without constraint: {$validatorName}";
                }
            }
        }

        return [
            'valid' => empty($issues),
            'has_constraints' => !empty($constraintFiles),
            'constraint_count' => count($constraintFiles),
            'validator_count' => count($validatorFiles),
            'issues' => $issues,
        ];
    }

    /**
     * Register extension validators in DI container
     *
     * @param string $extensionName Extension name
     * @param string $extensionPath Extension path
     */
    private function registerExtensionValidators(string $extensionName, string $extensionPath): void
    {
        $constraints = $this->registry->getConstraintsByExtension($extensionName);

        foreach ($constraints as $constraintClass => $info) {
            $validatorClass = $info['validator'];

            // Register validator in DI container if not already registered
            if (!$this->container->has($validatorClass)) {
                $this->container->singleton($validatorClass, function () use ($validatorClass) {
                    return new $validatorClass();
                });
            }
        }
    }
}
