<?php

declare(strict_types=1);

namespace Glueful\Serialization\Registry;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Lazy Normalizer Registry
 *
 * Provides lazy loading of normalizers to improve performance
 * by only instantiating normalizers when they are actually needed.
 */
class LazyNormalizerRegistry
{
    /**
     * @var array<string, string> Maps class names to normalizer class names
     */
    private array $normalizers = [];

    /**
     * @var array<string, NormalizerInterface> Cached loaded normalizers
     */
    private array $loaded = [];

    /**
     * @var array<string, callable> Factory functions for normalizers
     */
    private array $factories = [];

    /**
     * Add a normalizer for a specific class
     */
    public function addNormalizer(string $class, string $normalizerClass): void
    {
        $this->normalizers[$class] = $normalizerClass;
    }

    /**
     * Add normalizer with factory function
     */
    public function addNormalizerFactory(string $class, callable $factory): void
    {
        $this->factories[$class] = $factory;
    }

    /**
     * Get normalizer for a specific class (lazy loaded)
     */
    public function getNormalizer(string $class): ?NormalizerInterface
    {
        // Check if we have a factory for this class
        if (isset($this->factories[$class])) {
            if (!isset($this->loaded[$class])) {
                $this->loaded[$class] = ($this->factories[$class])();
            }
            return $this->loaded[$class];
        }

        // Check if we have a normalizer class registered
        if (!isset($this->normalizers[$class])) {
            return null;
        }

        // Lazy load the normalizer
        if (!isset($this->loaded[$class])) {
            $normalizerClass = $this->normalizers[$class];
            $this->loaded[$class] = container()->get($normalizerClass);
        }

        return $this->loaded[$class];
    }

    /**
     * Get normalizer for an object instance
     */
    public function getNormalizerForObject(object $object): ?NormalizerInterface
    {
        $class = get_class($object);

        // Try exact class match first
        $normalizer = $this->getNormalizer($class);
        if ($normalizer) {
            return $normalizer;
        }

        // Try parent classes and interfaces
        $reflection = new \ReflectionClass($object);

        // Check parent classes
        while ($parent = $reflection->getParentClass()) {
            $normalizer = $this->getNormalizer($parent->getName());
            if ($normalizer) {
                // Cache this result for the original class
                $this->normalizers[$class] = $this->normalizers[$parent->getName()];
                return $normalizer;
            }
            $reflection = $parent;
        }

        // Check interfaces
        foreach (class_implements($object) as $interface) {
            $normalizer = $this->getNormalizer($interface);
            if ($normalizer) {
                // Cache this result for the original class
                $this->normalizers[$class] = $this->normalizers[$interface];
                return $normalizer;
            }
        }

        return null;
    }

    /**
     * Check if a normalizer exists for a class
     */
    public function hasNormalizer(string $class): bool
    {
        return isset($this->normalizers[$class]) || isset($this->factories[$class]);
    }

    /**
     * Get all registered normalizer classes
     */
    public function getRegisteredNormalizers(): array
    {
        return array_keys($this->normalizers + $this->factories);
    }

    /**
     * Get all loaded normalizers
     */
    public function getLoadedNormalizers(): array
    {
        return $this->loaded;
    }

    /**
     * Check if a normalizer is loaded
     */
    public function isLoaded(string $class): bool
    {
        return isset($this->loaded[$class]);
    }

    /**
     * Preload specific normalizers
     */
    public function preload(array $classes): void
    {
        foreach ($classes as $class) {
            $this->getNormalizer($class);
        }
    }

    /**
     * Clear loaded normalizers cache
     */
    public function clearCache(): void
    {
        $this->loaded = [];
    }

    /**
     * Remove normalizer registration
     */
    public function removeNormalizer(string $class): void
    {
        unset($this->normalizers[$class], $this->factories[$class], $this->loaded[$class]);
    }

    /**
     * Get registry statistics
     */
    public function getStats(): array
    {
        return [
            'registered_normalizers' => count($this->normalizers) + count($this->factories),
            'loaded_normalizers' => count($this->loaded),
            'memory_usage' => memory_get_usage(true),
            'normalizer_classes' => array_keys($this->normalizers),
            'factory_classes' => array_keys($this->factories),
            'loaded_classes' => array_keys($this->loaded),
        ];
    }

    /**
     * Bulk register normalizers from array
     */
    public function registerNormalizers(array $mappings): void
    {
        foreach ($mappings as $class => $normalizerClass) {
            if (is_callable($normalizerClass)) {
                $this->addNormalizerFactory($class, $normalizerClass);
            } else {
                $this->addNormalizer($class, $normalizerClass);
            }
        }
    }

    /**
     * Auto-discover normalizers in a namespace
     */
    public function discoverNormalizers(string $namespace, string $path): int
    {
        $discovered = 0;

        if (!is_dir($path)) {
            return $discovered;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $this->extractClassNameFromFile($file->getPathname(), $namespace);

                if ($className && $this->isNormalizerClass($className)) {
                    // Extract the class it normalizes from the class name or reflection
                    $targetClass = $this->extractTargetClass($className);
                    if ($targetClass) {
                        $this->addNormalizer($targetClass, $className);
                        $discovered++;
                    }
                }
            }
        }

        return $discovered;
    }

    /**
     * Extract class name from file path
     */
    private function extractClassNameFromFile(string $filePath, string $namespace): ?string
    {
        $content = file_get_contents($filePath);

        // Simple regex to extract class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $namespace . '\\' . $matches[1];
        }

        return null;
    }

    /**
     * Check if a class is a normalizer
     */
    private function isNormalizerClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        $reflection = new \ReflectionClass($className);
        return $reflection->implementsInterface(NormalizerInterface::class) ||
               $reflection->implementsInterface(DenormalizerInterface::class);
    }

    /**
     * Extract target class from normalizer class
     */
    private function extractTargetClass(string $normalizerClass): ?string
    {
        // Try to instantiate and check supportsNormalization
        try {
            $normalizer = container()->get($normalizerClass);

            if (method_exists($normalizer, 'getSupportedTypes')) {
                $supportedTypes = $normalizer->getSupportedTypes(null);
                if (!empty($supportedTypes)) {
                    return array_key_first($supportedTypes);
                }
            }
        } catch (\Exception $e) {
            // Ignore instantiation errors
        }

        // Fallback: extract from class name convention (e.g., UserNormalizer -> User)
        $baseName = basename(str_replace('\\', '/', $normalizerClass));
        if (str_ends_with($baseName, 'Normalizer')) {
            $targetName = substr($baseName, 0, -10); // Remove 'Normalizer'
            // This is a simplified approach - real implementation might need more sophisticated mapping
            return $targetName;
        }

        return null;
    }
}
