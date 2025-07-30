<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * InsertBuilder Interface
 *
 * Defines the contract for INSERT query construction.
 * This interface ensures consistent INSERT query building
 * across different implementations.
 */
interface InsertBuilderInterface
{
    /**
     * Insert single record
     */
    public function insert(string $table, array $data): int;

    /**
     * Insert multiple records in batch
     */
    public function insertBatch(string $table, array $rows): int;

    /**
     * Insert or update on duplicate key
     */
    public function upsert(string $table, array $data, array $updateColumns): int;

    /**
     * Build INSERT SQL query
     */
    public function buildInsertQuery(string $table, array $data): string;

    /**
     * Build batch INSERT SQL query
     */
    public function buildBatchInsertQuery(string $table, array $rows): string;

    /**
     * Build UPSERT SQL query
     */
    public function buildUpsertQuery(string $table, array $data, array $updateColumns): string;

    /**
     * Validate insert data
     */
    public function validateData(array $data): void;

    /**
     * Validate batch insert data
     */
    public function validateBatchData(array $rows): void;
}
