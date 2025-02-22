<?php

namespace Glueful\Database\Schema;

interface SchemaManager
{
    public function createTable(string $table, array $columns, array $options = []): bool;
    public function dropTable(string $table): bool;
    public function addColumn(string $table, string $column, array $definition): bool;
    public function dropColumn(string $table, string $column): bool;
    public function createIndex(string $table, string $indexName, array $columns, bool $unique = false): bool;
    public function dropIndex(string $table, string $indexName): bool;
    public function getTables(): array;
    public function getTableColumns(string $table): array;
}