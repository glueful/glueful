<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\ConstraintCompiler;
use Glueful\DI\Interfaces\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use ReflectionClass;
use ReflectionProperty;

/**
 * Lazy Validation Provider
 *
 * Provides lazy loading of validation constraints to improve performance.
 * Only loads and compiles constraints when they are actually needed.
 */
class LazyValidationProvider
{
    private ContainerInterface $container;
    private ConstraintCompiler $compiler;
    private array $config;

    /** @var array<string, array> Cache for loaded constraints */
    private array $constraintCache = [];

    /** @var array<string, bool> Track which classes have been loaded */
    private array $loadedClasses = [];

    /** @var array<string, float> Performance metrics */
    private array $metrics = [];

    public function __construct(
        ContainerInterface $container,
        ConstraintCompiler $compiler,
        array $config = []
    ) {
        $this->container = $container;
        $this->compiler = $compiler;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Get constraints for a class (lazy loaded)
     *
     * @param string $className Class name
     * @return array Array of constraints
     */
    public function getConstraintsFor(string $className): array
    {
        $startTime = microtime(true);

        // Return cached constraints if available
        if (isset($this->constraintCache[$className])) {
            $this->recordMetric('cache_hit', microtime(true) - $startTime);
            return $this->constraintCache[$className];
        }

        // Load constraints lazily
        $constraints = $this->loadConstraints($className);

        // Cache for future use
        if ($this->config['cache_constraints']) {
            $this->constraintCache[$className] = $constraints;
        }

        $this->recordMetric('load_time', microtime(true) - $startTime);
        return $constraints;
    }

    /**
     * Load constraints for a class
     *
     * @param string $className Class name
     * @return array Loaded constraints
     */
    private function loadConstraints(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        // Use compiler for compiled constraints if enabled
        if ($this->compiler->isCompilationEnabled()) {
            return $this->loadCompiledConstraints($className);
        }

        // Load constraints via reflection
        return $this->loadReflectionConstraints($className);
    }

    /**
     * Load compiled constraints
     *
     * @param string $className Class name
     * @return array Compiled constraints
     */
    private function loadCompiledConstraints(string $className): array
    {
        $compiledConstraints = $this->compiler->compileConstraints($className);

        return $this->transformCompiledConstraints($compiledConstraints);
    }

    /**
     * Load constraints using reflection
     *
     * @param string $className Class name
     * @return array Reflection-based constraints
     */
    private function loadReflectionConstraints(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $constraints = [
            'class' => $this->getClassConstraints($reflection),
            'properties' => $this->getPropertyConstraints($reflection),
        ];

        return $constraints;
    }

    /**
     * Get class-level constraints via reflection
     *
     * @param ReflectionClass $reflection Class reflection
     * @return array Class constraints
     */
    private function getClassConstraints(ReflectionClass $reflection): array
    {
        $constraints = [];
        $attributes = $reflection->getAttributes();

        foreach ($attributes as $attribute) {
            $constraint = $this->createConstraintFromAttribute($attribute);
            if ($constraint) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * Get property-level constraints via reflection
     *
     * @param ReflectionClass $reflection Class reflection
     * @return array Property constraints
     */
    private function getPropertyConstraints(ReflectionClass $reflection): array
    {
        $constraints = [];
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyConstraints = $this->getPropertyConstraintInstances($property);

            if (!empty($propertyConstraints)) {
                $constraints[$property->getName()] = $propertyConstraints;
            }
        }

        return $constraints;
    }

    /**
     * Get constraint instances for a property
     *
     * @param ReflectionProperty $property Property reflection
     * @return array Property constraints
     */
    private function getPropertyConstraintInstances(ReflectionProperty $property): array
    {
        $constraints = [];
        $attributes = $property->getAttributes();

        foreach ($attributes as $attribute) {
            $constraint = $this->createConstraintFromAttribute($attribute);
            if ($constraint) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * Create constraint instance from attribute
     *
     * @param \ReflectionAttribute $attribute Attribute reflection
     * @return Constraint|null Constraint instance or null
     */
    private function createConstraintFromAttribute(\ReflectionAttribute $attribute): ?Constraint
    {
        $attributeClass = $attribute->getName();

        // Check if it's a valid constraint class
        if (!$this->isValidConstraintClass($attributeClass)) {
            return null;
        }

        try {
            $arguments = $attribute->getArguments();
            return new $attributeClass(...$arguments);
        } catch (\Exception $e) {
            // Log error but don't fail
            error_log("Failed to create constraint {$attributeClass}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a class is a valid constraint
     *
     * @param string $className Class name
     * @return bool True if valid constraint
     */
    private function isValidConstraintClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);
            return $reflection->isSubclassOf(Constraint::class);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Transform compiled constraints to usable format
     *
     * @param array $compiledConstraints Compiled constraints
     * @return array Transformed constraints
     */
    private function transformCompiledConstraints(array $compiledConstraints): array
    {
        $constraints = [
            'class' => [],
            'properties' => [],
        ];

        // Transform class constraints
        foreach ($compiledConstraints['class'] as $constraintData) {
            $constraint = $this->createConstraintFromData($constraintData);
            if ($constraint) {
                $constraints['class'][] = $constraint;
            }
        }

        // Transform property constraints
        foreach ($compiledConstraints['properties'] as $propertyName => $propertyConstraints) {
            $constraints['properties'][$propertyName] = [];

            foreach ($propertyConstraints as $constraintData) {
                $constraint = $this->createConstraintFromData($constraintData);
                if ($constraint) {
                    $constraints['properties'][$propertyName][] = $constraint;
                }
            }
        }

        return $constraints;
    }

    /**
     * Create constraint instance from compiled data
     *
     * @param array $constraintData Constraint data
     * @return Constraint|null Constraint instance or null
     */
    private function createConstraintFromData(array $constraintData): ?Constraint
    {
        $constraintClass = $constraintData['class'];
        $arguments = $constraintData['arguments'] ?? [];

        if (!$this->isValidConstraintClass($constraintClass)) {
            return null;
        }

        try {
            return new $constraintClass(...$arguments);
        } catch (\Exception $e) {
            error_log("Failed to create constraint from data {$constraintClass}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Preload constraints for multiple classes
     *
     * @param array<string> $classNames Array of class names
     * @return array Preload results
     */
    public function preloadConstraints(array $classNames): array
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($classNames as $className) {
            try {
                $constraints = $this->getConstraintsFor($className);
                $results[$className] = [
                    'success' => true,
                    'constraint_count' => $this->countConstraints($constraints),
                ];
            } catch (\Exception $e) {
                $results[$className] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->recordMetric('preload_time', microtime(true) - $startTime);
        $this->recordMetric('preloaded_classes', count($classNames));

        return $results;
    }

    /**
     * Count constraints in constraint array
     *
     * @param array $constraints Constraints array
     * @return int Total constraint count
     */
    private function countConstraints(array $constraints): int
    {
        $count = count($constraints['class'] ?? []);

        foreach ($constraints['properties'] ?? [] as $propertyConstraints) {
            $count += count($propertyConstraints);
        }

        return $count;
    }

    /**
     * Check if class constraints are loaded
     *
     * @param string $className Class name
     * @return bool True if loaded
     */
    public function isClassLoaded(string $className): bool
    {
        return isset($this->loadedClasses[$className]);
    }

    /**
     * Get loaded class names
     *
     * @return array<string> Array of loaded class names
     */
    public function getLoadedClasses(): array
    {
        return array_keys($this->loadedClasses);
    }

    /**
     * Clear constraint cache
     *
     * @param string|null $className Optional class name to clear specific cache
     * @return bool True if cache was cleared
     */
    public function clearCache(?string $className = null): bool
    {
        if ($className) {
            unset($this->constraintCache[$className]);
            unset($this->loadedClasses[$className]);
            return true;
        }

        $this->constraintCache = [];
        $this->loadedClasses = [];
        return true;
    }

    /**
     * Get performance statistics
     *
     * @return array Performance statistics
     */
    public function getStatistics(): array
    {
        return [
            'loaded_classes' => count($this->loadedClasses),
            'cached_constraints' => count($this->constraintCache),
            'cache_enabled' => $this->config['cache_constraints'],
            'lazy_loading_enabled' => $this->config['lazy_loading'],
            'metrics' => $this->getMetricsSummary(),
        ];
    }

    /**
     * Get metrics summary
     *
     * @return array Metrics summary
     */
    private function getMetricsSummary(): array
    {
        $summary = [];

        foreach ($this->metrics as $metric => $values) {
            if (is_array($values) && !empty($values)) {
                $summary[$metric] = [
                    'count' => count($values),
                    'total' => array_sum($values),
                    'average' => array_sum($values) / count($values),
                    'min' => min($values),
                    'max' => max($values),
                ];
            } else {
                $summary[$metric] = [
                    'count' => 0,
                    'total' => 0,
                    'average' => 0,
                    'min' => 0,
                    'max' => 0,
                ];
            }
        }

        return $summary;
    }

    /**
     * Optimize constraint loading for production
     *
     * @param array<string> $frequentClasses Classes that are validated frequently
     * @return array Optimization results
     */
    public function optimizeForProduction(array $frequentClasses): array
    {
        $results = [];

        // Preload frequently used classes
        $preloadResults = $this->preloadConstraints($frequentClasses);

        // Warm up compiler cache
        if ($this->compiler->isCompilationEnabled()) {
            $warmupResults = $this->compiler->warmupCache($frequentClasses);
            $results['warmup'] = $warmupResults;
        }

        $results['preload'] = $preloadResults;
        $results['optimized_classes'] = count($frequentClasses);

        return $results;
    }

    /**
     * Record performance metric
     *
     * @param string $metric Metric name
     * @param float $value Metric value
     */
    private function recordMetric(string $metric, float $value): void
    {
        if (!isset($this->metrics[$metric])) {
            $this->metrics[$metric] = [];
        }

        $this->metrics[$metric][] = $value;
    }

    /**
     * Get default configuration
     *
     * @return array Default config
     */
    private function getDefaultConfig(): array
    {
        return [
            'cache_constraints' => true,
            'lazy_loading' => true,
            'preload_common' => true,
            'debug' => false,
        ];
    }

    /**
     * Get cache memory usage
     *
     * @return array Memory usage information
     */
    public function getCacheMemoryUsage(): array
    {
        $memoryUsage = 0;

        foreach ($this->constraintCache as $constraints) {
            $memoryUsage += strlen(serialize($constraints));
        }

        return [
            'total_memory' => $memoryUsage,
            'cached_classes' => count($this->constraintCache),
            'average_per_class' => count($this->constraintCache) > 0 ?
                $memoryUsage / count($this->constraintCache) : 0,
        ];
    }
}
