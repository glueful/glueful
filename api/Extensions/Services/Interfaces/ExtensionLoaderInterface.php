<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services\Interfaces;

interface ExtensionLoaderInterface
{
    /**
     * Load an extension by name
     */
    public function loadExtension(string $name): bool;

    /**
     * Unload an extension by name
     */
    public function unloadExtension(string $name): bool;

    /**
     * Check if an extension is currently loaded
     */
    public function isLoaded(string $name): bool;

    /**
     * Get list of all loaded extensions
     */
    public function getLoadedExtensions(): array;

    /**
     * Validate extension structure and files
     */
    public function validateStructure(string $path): bool;

    /**
     * Register extension namespace for autoloading
     */
    public function registerNamespace(string $name, string $path): void;

    /**
     * Load extension routes
     */
    public function loadRoutes(string $name): void;

    /**
     * Load extension service providers
     */
    public function loadServiceProviders(string $name): void;

    /**
     * Initialize a loaded extension
     */
    public function initializeExtension(string $name): bool;

    /**
     * Discover extensions in directory
     */
    public function discoverExtensions(?string $extensionsPath = null): array;

    /**
     * Set debug mode
     */
    public function setDebugMode(bool $enable = true): void;

    /**
     * Set class loader
     */
    public function setClassLoader(\Composer\Autoload\ClassLoader $classLoader): void;

    /**
     * Get registered namespaces
     */
    public function getRegisteredNamespaces(): array;
}
