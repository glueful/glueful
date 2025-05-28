<?php

declare(strict_types=1);

namespace Glueful;

interface IExtensions
{
    /**
     * Process extension request
     *
     * @param array<string, mixed> $queryParams GET parameters
     * @param array<string, mixed> $bodyParams POST parameters
     * @return array<string, mixed> Response data
     */
    public static function process(array $queryParams, array $bodyParams): array;

    /**
     * Initialize extension
     *
     * @return void
     */
    public static function initialize(): void;

    /**
     * Get the extension's service provider
     *
     * @return \Glueful\DI\Interfaces\ServiceProviderInterface
     */
    public static function getServiceProvider(): \Glueful\DI\Interfaces\ServiceProviderInterface;

    /**
     * Get extension metadata
     *
     * @return array<string, mixed> Extension metadata
     */
    public static function getMetadata(): array;

    /**
     * Get extension dependencies
     *
     * @return array<string> List of required extensions
     */
    public static function getDependencies(): array;

    /**
     * Validate extension security
     *
     * @return array<string, mixed> Security configuration
     */
    public static function validateSecurity(): array;

    /**
     * Check extension health
     *
     * @return array<string, mixed> Health status
     */
    public static function checkHealth(): array;
}
