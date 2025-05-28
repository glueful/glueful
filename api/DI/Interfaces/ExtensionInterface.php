<?php

declare(strict_types=1);

namespace Glueful\DI\Interfaces;

/**
 * Extension Interface
 * 
 * Defines the contract for extensions that integrate with the DI container.
 * Extensions can register services, define routes, and hook into the application lifecycle.
 */
interface ExtensionInterface
{
    /**
     * Get the extension name
     */
    public function getName(): string;

    /**
     * Get the extension version
     */
    public function getVersion(): string;

    /**
     * Get the extension description
     */
    public function getDescription(): string;

    /**
     * Register services with the container
     * 
     * This method is called during extension loading to register
     * all services that the extension provides.
     */
    public function register(ContainerInterface $container): void;

    /**
     * Boot the extension
     * 
     * This method is called after all extensions have registered their services.
     * Use this for any initialization that depends on other services.
     */
    public function boot(ContainerInterface $container): void;

    /**
     * Register extension routes
     * 
     * This method is called to register all routes that the extension provides.
     */
    public function routes(): void;

    /**
     * Get extension dependencies
     * 
     * @return array List of extension names this extension depends on
     */
    public function getDependencies(): array;

    /**
     * Check if the extension is enabled
     */
    public function isEnabled(): bool;
}