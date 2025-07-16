<?php

declare(strict_types=1);

namespace Glueful\DI;

use Glueful\DI\Attributes\Service;
use Glueful\DI\Attributes\Tag;
use Glueful\DI\Attributes\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Attribute Reader
 *
 * Reads PHP 8+ attributes from classes and configures DI container accordingly.
 * Processes Service, Tag, and Autowire attributes to automatically register
 * and configure services in the Symfony DI container.
 *
 * @package Glueful\DI
 */
class AttributeReader
{
    /**
     * Process class attributes and register services
     *
     * @param ContainerBuilder $container DI container to configure
     * @param string $className Class to process
     * @return void
     */
    public function processClass(ContainerBuilder $container, string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        $reflection = new \ReflectionClass($className);
        $serviceAttribute = $this->getServiceAttribute($reflection);

        if (!$serviceAttribute) {
            return; // Not marked as a service
        }

        $serviceId = $serviceAttribute->getId($className);
        $definition = new Definition($className);

        // Configure basic service properties
        $definition->setPublic($serviceAttribute->isPublic());
        $definition->setShared($serviceAttribute->isShared());
        $definition->setLazy($serviceAttribute->isLazy());

        // Process constructor autowiring
        $this->processConstructorAutowiring($definition, $reflection);

        // Process service tags
        $this->processServiceTags($definition, $reflection);

        // Process factory if specified
        if ($serviceAttribute->getFactory()) {
            $definition->setFactory($serviceAttribute->getFactory());
        }

        // Process method calls
        foreach ($serviceAttribute->getCalls() as $call) {
            $definition->addMethodCall($call['method'], $call['arguments'] ?? []);
        }

        // Register the service
        $container->setDefinition($serviceId, $definition);
    }

    /**
     * Process multiple classes from a directory
     *
     * @param ContainerBuilder $container DI container to configure
     * @param string $directory Directory to scan for classes
     * @param string $namespace Base namespace for classes
     * @return int Number of services processed
     */
    public function processDirectory(ContainerBuilder $container, string $directory, string $namespace): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $processed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = str_replace($directory . '/', '', $file->getPathname());
                $className = $namespace . '\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

                $this->processClass($container, $className);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Get Service attribute from class
     *
     * @param \ReflectionClass $reflection Class reflection
     * @return Service|null Service attribute or null
     */
    private function getServiceAttribute(\ReflectionClass $reflection): ?Service
    {
        $attributes = $reflection->getAttributes(Service::class);
        return $attributes ? $attributes[0]->newInstance() : null;
    }

    /**
     * Process constructor parameter autowiring
     *
     * @param Definition $definition Service definition
     * @param \ReflectionClass $reflection Class reflection
     * @return void
     */
    private function processConstructorAutowiring(Definition $definition, \ReflectionClass $reflection): void
    {
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return;
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $autowireAttribute = $this->getParameterAutowireAttribute($parameter);

            if ($autowireAttribute) {
                $arguments[] = $this->resolveAutowiredArgument($autowireAttribute, $parameter);
            } else {
                // Auto-wire by type if no explicit autowiring
                $type = $parameter->getType();
                if ($type && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    $arguments[] = new Reference($typeName);
                }
            }
        }

        if (!empty($arguments)) {
            $definition->setArguments($arguments);
        }
    }

    /**
     * Process service tags from attributes
     *
     * @param Definition $definition Service definition
     * @param \ReflectionClass $reflection Class reflection
     * @return void
     */
    private function processServiceTags(Definition $definition, \ReflectionClass $reflection): void
    {
        $tagAttributes = $reflection->getAttributes(Tag::class);

        foreach ($tagAttributes as $tagAttribute) {
            $tag = $tagAttribute->newInstance();
            $definition->addTag($tag->getName(), $tag->getAttributes());
        }
    }

    /**
     * Get Autowire attribute from parameter
     *
     * @param \ReflectionParameter $parameter Parameter reflection
     * @return Autowire|null Autowire attribute or null
     */
    private function getParameterAutowireAttribute(\ReflectionParameter $parameter): ?Autowire
    {
        $attributes = $parameter->getAttributes(Autowire::class);
        return $attributes ? $attributes[0]->newInstance() : null;
    }

    /**
     * Resolve autowired argument based on attribute
     *
     * @param Autowire $autowire Autowire attribute
     * @param \ReflectionParameter $parameter Parameter reflection
     * @return mixed Resolved argument
     */
    private function resolveAutowiredArgument(Autowire $autowire, \ReflectionParameter $parameter): mixed
    {
        switch ($autowire->getInjectionType()) {
            case 'service':
                return new Reference($autowire->getService());

            case 'parameter':
                return '%' . $autowire->getParameter() . '%';

            case 'value':
                return $autowire->getValue();

            case 'auto':
            default:
                // Auto-wire by type
                $type = $parameter->getType();
                if ($type && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    return new Reference($typeName);
                }
                return null;
        }
    }

    /**
     * Validate attribute configuration
     *
     * @param string $className Class name to validate
     * @return array Validation results
     */
    public function validateClass(string $className): array
    {
        $issues = [];

        try {
            if (!class_exists($className)) {
                $issues[] = "Class {$className} does not exist";
                return $issues;
            }

            $reflection = new \ReflectionClass($className);
            $serviceAttribute = $this->getServiceAttribute($reflection);

            if (!$serviceAttribute) {
                return $issues; // No service attribute, nothing to validate
            }

            // Validate constructor parameters
            $constructor = $reflection->getConstructor();
            if ($constructor) {
                foreach ($constructor->getParameters() as $parameter) {
                    $autowire = $this->getParameterAutowireAttribute($parameter);
                    if ($autowire) {
                        $validationResult = $this->validateAutowireAttribute($autowire, $parameter);
                        if ($validationResult) {
                            $issues[] = $validationResult;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $issues[] = "Error validating {$className}: " . $e->getMessage();
        }

        return $issues;
    }

    /**
     * Validate autowire attribute
     *
     * @param Autowire $autowire Autowire attribute
     * @param \ReflectionParameter $parameter Parameter reflection
     * @return string|null Validation error or null if valid
     */
    private function validateAutowireAttribute(Autowire $autowire, \ReflectionParameter $parameter): ?string
    {
        $injectionType = $autowire->getInjectionType();

        if ($injectionType === 'service' && empty($autowire->getService())) {
            return "Parameter {$parameter->getName()} has empty service ID";
        }

        if ($injectionType === 'parameter' && empty($autowire->getParameter())) {
            return "Parameter {$parameter->getName()} has empty parameter name";
        }

        return null;
    }
}
