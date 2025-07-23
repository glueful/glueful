<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Cache\CacheStore;
use ReflectionClass;
use ReflectionProperty;

/**
 * Constraint Compiler
 *
 * Compiles and caches validation constraints for improved performance.
 * Reduces the overhead of reflection and constraint building on each request.
 */
class ConstraintCompiler
{
    private CacheStore $cache;
    private array $config;
    private array $compiledConstraints = [];

    /** @var array<string, float> Performance metrics */
    private array $metrics = [];

    public function __construct(
        CacheStore $cache,
        array $config = []
    ) {
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Compile constraints for a class and cache them
     *
     * @param string $className Class name to compile constraints for
     * @return array Compiled constraints
     */
    public function compileConstraints(string $className): array
    {
        $startTime = microtime(true);

        // Check cache first
        if ($this->config['enable_cache']) {
            $cacheKey = $this->getCacheKey($className);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $this->recordMetric('cache_hit', microtime(true) - $startTime);
                return $cached;
            }
        }

        // Compile constraints
        $constraints = $this->buildConstraints($className);

        // Cache compiled constraints
        if ($this->config['enable_cache']) {
            $this->cache->set($cacheKey, $constraints, $this->config['cache_ttl']);
        }

        $this->recordMetric('compile_time', microtime(true) - $startTime);
        return $constraints;
    }

    /**
     * Build constraints for a class using reflection
     *
     * @param string $className Class name
     * @return array Compiled constraints
     */
    private function buildConstraints(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        $reflection = new ReflectionClass($className);
        $constraints = [
            'class' => $this->getClassConstraints($reflection),
            'properties' => $this->getPropertyConstraints($reflection),
            'metadata' => $this->getClassMetadata($reflection),
        ];

        return $constraints;
    }

    /**
     * Get class-level constraints
     *
     * @param ReflectionClass $reflection Class reflection
     * @return array Class constraints
     */
    private function getClassConstraints(ReflectionClass $reflection): array
    {
        $constraints = [];
        $attributes = $reflection->getAttributes();

        foreach ($attributes as $attribute) {
            $attributeClass = $attribute->getName();

            // Check if it's a validation constraint
            if ($this->isValidationConstraint($attributeClass)) {
                $constraints[] = [
                    'class' => $attributeClass,
                    'arguments' => $attribute->getArguments(),
                    'target' => 'class',
                ];
            }
        }

        return $constraints;
    }

    /**
     * Get property-level constraints
     *
     * @param ReflectionClass $reflection Class reflection
     * @return array Property constraints
     */
    private function getPropertyConstraints(ReflectionClass $reflection): array
    {
        $constraints = [];
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyConstraints = $this->getPropertyConstraintData($property);

            if (!empty($propertyConstraints)) {
                $constraints[$property->getName()] = $propertyConstraints;
            }
        }

        return $constraints;
    }

    /**
     * Get constraint data for a property
     *
     * @param ReflectionProperty $property Property reflection
     * @return array Property constraints
     */
    private function getPropertyConstraintData(ReflectionProperty $property): array
    {
        $constraints = [];
        $attributes = $property->getAttributes();

        foreach ($attributes as $attribute) {
            $attributeClass = $attribute->getName();

            // Check if it's a validation constraint
            if ($this->isValidationConstraint($attributeClass)) {
                $constraints[] = [
                    'class' => $attributeClass,
                    'arguments' => $attribute->getArguments(),
                    'target' => 'property',
                    'property' => $property->getName(),
                ];
            }
        }

        return $constraints;
    }

    /**
     * Get class metadata for validation
     *
     * @param ReflectionClass $reflection Class reflection
     * @return array Class metadata
     */
    private function getClassMetadata(ReflectionClass $reflection): array
    {
        return [
            'name' => $reflection->getName(),
            'short_name' => $reflection->getShortName(),
            'namespace' => $reflection->getNamespaceName(),
            'file' => $reflection->getFileName(),
            'hash' => $this->getClassHash($reflection),
            'compiled_at' => time(),
        ];
    }

    /**
     * Check if a class is a validation constraint
     *
     * @param string $className Class name to check
     * @return bool True if it's a validation constraint
     */
    private function isValidationConstraint(string $className): bool
    {
        // Check if it's a Symfony constraint
        if (str_starts_with($className, 'Symfony\\Component\\Validator\\Constraints\\')) {
            return true;
        }

        // Check if it's a Glueful constraint
        if (str_starts_with($className, 'Glueful\\Validation\\Constraints\\')) {
            return true;
        }

        // Check if it's an extension constraint
        if (str_contains($className, '\\Validation\\Constraints\\')) {
            return true;
        }

        // Check if class exists and extends Constraint
        if (class_exists($className)) {
            try {
                $reflection = new ReflectionClass($className);
                return $reflection->isSubclassOf('Symfony\\Component\\Validator\\Constraint');
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get cache key for a class
     *
     * @param string $className Class name
     * @return string Cache key
     */
    private function getCacheKey(string $className): string
    {
        $hash = $this->getClassHash(new ReflectionClass($className));
        return "validation_constraints:" . md5($className . $hash);
    }

    /**
     * Get hash for a class (for cache invalidation)
     *
     * @param ReflectionClass $reflection Class reflection
     * @return string Class hash
     */
    private function getClassHash(ReflectionClass $reflection): string
    {
        $factors = [
            $reflection->getFileName(),
            filemtime($reflection->getFileName()),
            $reflection->getName(),
        ];

        return md5(implode('|', $factors));
    }

    /**
     * Precompile constraints for multiple classes
     *
     * @param array<string> $classNames Array of class names
     * @return array Compilation results
     */
    public function precompileConstraints(array $classNames): array
    {
        $results = [];
        $startTime = microtime(true);

        foreach ($classNames as $className) {
            try {
                $constraints = $this->compileConstraints($className);
                $results[$className] = [
                    'success' => true,
                    'constraints_count' => $this->countConstraints($constraints),
                ];
            } catch (\Exception $e) {
                $results[$className] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->recordMetric('precompile_time', microtime(true) - $startTime);
        $this->recordMetric('precompiled_classes', count($classNames));

        return $results;
    }

    /**
     * Count constraints in compiled data
     *
     * @param array $constraints Compiled constraints
     * @return int Total constraint count
     */
    private function countConstraints(array $constraints): int
    {
        $count = count($constraints['class']);

        foreach ($constraints['properties'] as $propertyConstraints) {
            $count += count($propertyConstraints);
        }

        return $count;
    }

    /**
     * Clear compiled constraint cache
     *
     * @param string|null $className Optional class name to clear specific cache
     * @return bool True if cache was cleared
     */
    public function clearCache(?string $className = null): bool
    {
        if ($className) {
            $cacheKey = $this->getCacheKey($className);
            return $this->cache->delete($cacheKey);
        }

        // Clear all validation constraint caches
        return $this->cache->deletePattern('validation_constraints:*');
    }

    /**
     * Get compilation statistics
     *
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        return [
            'metrics' => $this->metrics,
            'cache_enabled' => $this->config['enable_cache'],
            'compilation_enabled' => $this->config['enable_compilation'],
            'cache_ttl' => $this->config['cache_ttl'],
            'compiled_classes' => count($this->compiledConstraints),
        ];
    }

    /**
     * Warm up constraint cache for common DTOs
     *
     * @param array<string> $dtoClasses Array of DTO class names
     * @return array Warm-up results
     */
    public function warmupCache(array $dtoClasses = []): array
    {
        if (empty($dtoClasses)) {
            $dtoClasses = $this->discoverDTOClasses();
        }

        return $this->precompileConstraints($dtoClasses);
    }

    /**
     * Discover DTO classes in the application
     *
     * @return array<string> Array of DTO class names
     */
    private function discoverDTOClasses(): array
    {
        $dtoClasses = [];
        $dtoPath = dirname(__DIR__) . '/DTOs';

        if (is_dir($dtoPath)) {
            $files = glob($dtoPath . '/*.php');

            foreach ($files as $file) {
                $className = 'Glueful\\DTOs\\' . basename($file, '.php');
                if (class_exists($className)) {
                    $dtoClasses[] = $className;
                }
            }
        }

        return $dtoClasses;
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
            'enable_cache' => true,
            'enable_compilation' => true,
            'cache_ttl' => 3600,
            'debug' => false,
        ];
    }

    /**
     * Check if constraint compilation is enabled
     *
     * @return bool True if enabled
     */
    public function isCompilationEnabled(): bool
    {
        return $this->config['enable_compilation'];
    }

    /**
     * Check if constraint caching is enabled
     *
     * @return bool True if enabled
     */
    public function isCacheEnabled(): bool
    {
        return $this->config['enable_cache'];
    }
}
