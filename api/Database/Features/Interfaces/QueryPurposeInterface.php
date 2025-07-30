<?php

declare(strict_types=1);

namespace Glueful\Database\Features\Interfaces;

/**
 * Interface for tracking business purpose of queries
 *
 * Provides methods to set and track the business purpose of database queries
 * for auditing, monitoring, and debugging purposes.
 */
interface QueryPurposeInterface
{
    /**
     * Set the business purpose for the current query
     *
     * @param string $purpose Description of the business purpose
     */
    public function setPurpose(string $purpose): void;

    /**
     * Get the current business purpose
     *
     * @return string|null The business purpose or null if not set
     */
    public function getPurpose(): ?string;

    /**
     * Clear the business purpose
     */
    public function clearPurpose(): void;

    /**
     * Check if a business purpose has been set
     */
    public function hasPurpose(): bool;

    /**
     * Set metadata associated with the purpose
     *
     * @param array $metadata Additional metadata for the purpose
     */
    public function setMetadata(array $metadata): void;

    /**
     * Get metadata associated with the purpose
     *
     * @return array The metadata array
     */
    public function getMetadata(): array;

    /**
     * Add a single metadata item
     *
     * @param string $key The metadata key
     * @param mixed $value The metadata value
     */
    public function addMetadata(string $key, mixed $value): void;

    /**
     * Format the purpose for logging
     *
     * @return string Formatted purpose string for logs
     */
    public function formatForLogging(): string;

    /**
     * Get full purpose context including metadata
     *
     * @return array Complete purpose context
     */
    public function getContext(): array;
}
