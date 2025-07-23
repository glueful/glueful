<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Export Operation Result
 *
 * Contains the result of exporting data from a table
 * for archival purposes.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ExportResult
{
    public function __construct(
        public readonly array $data,
        public readonly int $recordCount,
        public readonly array $metadata
    ) {
    }

    /**
     * Check if export contains data
     */
    public function hasData(): bool
    {
        return !empty($this->data);
    }

    /**
     * Get the first record
     */
    public function getFirstRecord(): ?array
    {
        return $this->data[0] ?? null;
    }

    /**
     * Get the last record
     */
    public function getLastRecord(): ?array
    {
        return empty($this->data) ? null : $this->data[array_key_last($this->data)];
    }
}
