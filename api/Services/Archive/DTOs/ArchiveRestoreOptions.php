<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive Restore Options
 *
 * Configuration options for restoring data from archives
 * including target table and conflict resolution.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ArchiveRestoreOptions
{
    public function __construct(
        public readonly ?string $targetTable = null,
        public readonly bool $createTableIfNotExists = false,
        public readonly string $conflictResolution = 'skip', // skip, overwrite, rename
        public readonly bool $restoreIndexes = true,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null
    ) {
    }

    /**
     * Create options for full restore
     */
    public static function fullRestore(?string $targetTable = null): self
    {
        return new self(
            targetTable: $targetTable,
            createTableIfNotExists: true,
            conflictResolution: 'skip',
            restoreIndexes: true
        );
    }

    /**
     * Create options for partial restore
     */
    public static function partialRestore(int $limit, int $offset = 0): self
    {
        return new self(
            limit: $limit,
            offset: $offset,
            conflictResolution: 'skip'
        );
    }

    /**
     * Create options for test restore
     */
    public static function testRestore(): self
    {
        return new self(
            createTableIfNotExists: false,
            conflictResolution: 'skip',
            restoreIndexes: false,
            limit: 10
        );
    }
}
