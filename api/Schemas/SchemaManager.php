<?php

declare(strict_types=1);

namespace Glueful\Api\Schemas;

/**
 * Schema Manager Interface
 * 
 * Defines the contract for database schema management operations.
 * Implementations should handle database-specific SQL syntax and features.
 */
interface SchemaManager
{
    /**
     * Create a new database table
     * 
     * @param string $tableName Name of table to create
     * @param array<string,string> $columns Column definitions ['name' => 'type definition']
     * @param array<array{type?: string, column: string}> $indexes Index definitions
     * @param array<array{
     *     name?: string,
     *     column: string,
     *     referenceTable: string,
     *     referenceColumn: string,
     *     onDelete?: string,
     *     onUpdate?: string
     * }> $foreignKeys Foreign key constraints
     * @return bool True if table created successfully
     */
    public function createTable(string $tableName, array $columns, array $indexes = [], array $foreignKeys = []): bool;

    /**
     * Drop an existing database table
     * 
     * @param string $tableName Name of table to drop
     * @return bool True if table dropped successfully
     * @throws \PDOException If table cannot be dropped
     */
    public function dropTable(string $tableName): bool;

    /**
     * Alter an existing table structure
     * 
     * @param string $tableName Name of the table to alter
     * @param array<string,array<string,string>> $modifications Column modifications
     * @return bool True if modifications applied successfully
     * @throws \PDOException If modifications cannot be applied
     */
    public function alterTable(string $tableName, array $modifications): bool;

    /**
     * Add a column to an existing table
     * 
     * @param string $tableName Name of the table
     * @param string $columnName Name of the column to add
     * @param string $columnDefinition Column type and constraints
     * @return bool True if column added successfully
     */
    public function addColumn(string $tableName, string $columnName, string $columnDefinition): bool;

    /**
     * Drop a column from an existing table
     * 
     * @param string $tableName Name of the table
     * @param string $columnName Name of the column to drop
     * @return bool True if column dropped successfully
     */
    public function dropColumn(string $tableName, string $columnName): bool;

    /**
     * Rename a column in an existing table
     * 
     * @param string $tableName Name of the table
     * @param string $oldName Current column name
     * @param string $newName New column name
     * @return bool True if column renamed successfully
     */
    public function renameColumn(string $tableName, string $oldName, string $newName): bool;

    /**
     * Modify a column in an existing table
     * 
     * @param string $tableName Name of the table
     * @param string $columnName Name of the column to modify
     * @param string $newDefinition New column type and constraints
     * @return bool True if column modified successfully
     */
    public function modifyColumn(string $tableName, string $columnName, string $newDefinition): bool;

    /**
     * Add an index to an existing table
     * 
     * @param string $tableName Name of table to add index to
     * @param string $column Column or columns to index
     * @param string $type Index type (INDEX, UNIQUE, FULLTEXT, etc)
     * @param string|null $indexName Optional custom index name
     * @return bool True if index added successfully
     * @throws \PDOException If index cannot be created
     */
    public function addIndex(string $tableName, string $column, string $type = 'INDEX', string $indexName = null): bool;

    /**
     * Add a foreign key constraint to an existing table
     * 
     * @param string $tableName Name of table to add constraint to
     * @param string $column Column in this table
     * @param string $referenceTable Referenced table name
     * @param string $referenceColumn Referenced column name
     * @param string $onDelete Action on delete (CASCADE, SET NULL, etc)
     * @param string $onUpdate Action on update (CASCADE, SET NULL, etc)
     * @param string|null $fkName Optional custom constraint name
     * @return bool True if constraint added successfully
     * @throws \PDOException If foreign key cannot be created
     */
    public function addForeignKey(
        string $tableName,
        string $column,
        string $referenceTable,
        string $referenceColumn,
        string $onDelete = 'CASCADE',
        string $onUpdate = 'CASCADE',
        string $fkName = null
    ): bool;

    /**
     * Insert data into a table
     * 
     * @param string $tableName Name of table to insert into
     * @param array<string,mixed> $data Associative array of column => value pairs
     * @return int|string|false Last insert ID or false on failure
     * @throws \PDOException If insert fails
     */
    public function insert(string $tableName, array $data): int|string|false;

    /**
     * Delete records from a table
     * 
     * @param string $tableName Name of table to delete from
     * @param array<string,mixed> $conditions WHERE conditions as column => value pairs
     * @return int Number of affected rows
     * @throws \PDOException If delete fails
     */
    public function delete(string $tableName, array $conditions): int;

    /**
     * Get data from a table
     * 
     * @param string $tableName Table to query
     * @param array<string,mixed> $options Query options (fields, where, order, limit, etc)
     * @return array<int,array<string,mixed>> Query results
     */
    public function getData(string $tableName, array $options = []): array;

    /**
     * Begin a database transaction
     * 
     * @return bool True if transaction started successfully
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction
     * 
     * @return bool True if commit was successful
     */
    public function commit(): bool;

    /**
     * Rollback the current transaction
     * 
     * @return bool True if rollback was successful
     */
    public function rollBack(): bool;
}
