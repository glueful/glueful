<?php

declare(strict_types=1);

namespace Glueful\DI;

use Glueful\DI\Exceptions\ContainerException;
use Glueful\DI\Exceptions\ServiceNotFoundException;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use ReflectionClass;
use ReflectionParameter;
use Closure;

/**
 * Dependency Injection Container
 *
 * Provides centralized dependency management with auto-wiring capabilities,
 * service lifecycle management, and service provider support.
 */
class Container implements ContainerInterface
{
    private array $bindings = [];
    private array $instances = [];
    private array $singletons = [];
    private array $aliases = [];
    private array $serviceProviders = [];

    public function __construct()
    {
        // Bind the container to itself
        $this->instance(ContainerInterface::class, $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Bind a service to the container
     */
    public function bind(string $abstract, mixed $concrete = null, bool $singleton = false): void
    {

        // Remove existing instance if rebinding
        unset($this->instances[$abstract]);

        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'singleton' => $singleton,
            'shared' => false
        ];

        if ($singleton) {
            $this->singletons[$abstract] = true;
        }
    }

    /**
     * Bind a singleton service
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Bind an existing instance
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
    }

    /**
     * Create an alias for a service
     */
    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Get a service from the container
     */
    public function get(string $id): mixed
    {
        try {
            return $this->resolve($id);
        } catch (ServiceNotFoundException $e) {
            // Re-throw PSR-11 compliant exception
            throw $e;
        } catch (\Exception $e) {
            throw new ContainerException("Error resolving service '$id': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if a service exists
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) ||
               isset($this->instances[$id]) ||
               isset($this->aliases[$id]) ||
               class_exists($id);
    }

    /**
     * Resolve a service with auto-wiring
     */
    public function resolve(string $abstract): object
    {
        // Check for alias
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        // Return existing instance if singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Get the concrete implementation
        $concrete = $this->getConcrete($abstract);

        // Build the service
        $instance = $this->build($concrete);

        // Store singleton instances
        if ($this->isSingleton($abstract)) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Build a service instance with dependency injection
     */
    private function build(mixed $concrete): object
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        if (is_string($concrete) && class_exists($concrete)) {
            return $this->buildClass($concrete);
        }

        if (is_string($concrete)) {
            throw new ServiceNotFoundException("Class '$concrete' not found");
        }

        throw new ContainerException("Cannot build service for: " . print_r($concrete, true));
    }

    /**
     * Build a class with dependency injection
     */
    private function buildClass(string $className): object
    {
        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class '$className' is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $dependencies = $this->resolveDependencies($constructor->getParameters());

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $this->resolveDependency($parameter);
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Resolve a single dependency
     */
    private function resolveDependency(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            throw new ContainerException("Cannot resolve parameter '{$parameter->getName()}' without type hint");
        }

        // Handle both PHP 7.0 and 7.1+ compatibility
        if (method_exists($type, 'getName')) {
            $typeName = $type->getName();
            $isBuiltin = method_exists($type, 'isBuiltin') ? $type->isBuiltin() : false;
        } else {
            // PHP 7.0 compatibility
            $typeName = (string) $type;
            $builtinTypes = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object'];
            $isBuiltin = in_array($typeName, $builtinTypes);
        }

        // Handle built-in types
        if ($isBuiltin) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            throw new ContainerException(
                "Cannot resolve built-in type '$typeName' for parameter '{$parameter->getName()}'"
            );
        }

        // Resolve class/interface dependencies
        try {
            return $this->resolve($typeName);
        } catch (\Exception $e) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($parameter->allowsNull()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Get concrete implementation for abstract
     */
    private function getConcrete(string $abstract): mixed
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Check if service is registered as singleton
     */
    private function isSingleton(string $abstract): bool
    {
        return isset($this->singletons[$abstract]) ||
               (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['singleton']);
    }

    /**
     * Register a service provider
     */
    public function register(ServiceProviderInterface $provider): void
    {
        $this->serviceProviders[] = $provider;
        $provider->register($this);
    }

    /**
     * Boot all registered service providers
     */
    public function boot(): void
    {
        foreach ($this->serviceProviders as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot($this);
            }
        }
    }


    /**
     * Get all registered bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Call a method with dependency injection
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            [$object, $method] = $callback;
            $reflection = new \ReflectionMethod($object, $method);
        } else {
            $reflection = new \ReflectionFunction($callback);
        }

        $dependencies = $this->resolveDependencies($reflection->getParameters());

        // Merge provided parameters with resolved dependencies
        $dependencies = array_merge($dependencies, $parameters);

        return call_user_func_array($callback, $dependencies);
    }
}
