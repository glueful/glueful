<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Archive Operation Result
 *
 * Contains the result of an archive operation including
 * success status, metadata, and any error information.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ArchiveResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $archiveUuid = null,
        public readonly ?int $recordCount = null,
        public readonly ?int $fileSize = null,
        public readonly ?string $filePath = null,
        public readonly ?string $error = null,
        public readonly array $metadata = []
    ) {
    }

    /**
     * Create a successful archive result
     */
    public static function success(
        string $archiveUuid,
        int $recordCount,
        int $fileSize,
        string $filePath,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            archiveUuid: $archiveUuid,
            recordCount: $recordCount,
            fileSize: $fileSize,
            filePath: $filePath,
            metadata: $metadata
        );
    }

    /**
     * Create a failed archive result
     */
    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
