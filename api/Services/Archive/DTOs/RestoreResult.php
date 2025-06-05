<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive Restore Result
 *
 * Contains the result of a restore operation including
 * success status and restoration metrics.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class RestoreResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $recordsRestored = null,
        public readonly ?string $targetTable = null,
        public readonly ?string $error = null,
        public readonly array $conflicts = [],
        public readonly array $metadata = []
    ) {
    }

    /**
     * Create a successful restore result
     */
    public static function success(
        int $recordsRestored,
        string $targetTable,
        array $conflicts = [],
        array $metadata = []
    ): self {
        return new self(
            success: true,
            recordsRestored: $recordsRestored,
            targetTable: $targetTable,
            conflicts: $conflicts,
            metadata: $metadata
        );
    }

    /**
     * Create a failed restore result
     */
    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }

    /**
     * Check if there were any conflicts during restore
     */
    public function hasConflicts(): bool
    {
        return !empty($this->conflicts);
    }

    /**
     * Get the number of conflicts
     */
    public function getConflictCount(): int
    {
        return count($this->conflicts);
    }
}
