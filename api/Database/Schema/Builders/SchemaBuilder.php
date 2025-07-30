<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\Builders;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderInterface;
use Glueful\Database\Schema\Interfaces\SqlGeneratorInterface;
use Glueful\Database\Connection;

/**
 * Concrete Schema Builder Implementation
 *
 * Main entry point for the schema building system. Manages database
 * connections, SQL generation, and coordinates table building operations.
 *
 * Features:
 * - Database-agnostic schema operations
 * - Transaction support for batched operations
 * - SQL preview and validation
 * - Connection management
 * - Error handling and rollback
 *
 * Example usage:
 * ```php
 * $schema = new SchemaBuilder($connection, $sqlGenerator);
 *
 * $schema->table('users')
 *     ->id()
 *     ->string('email')->unique()
 *     ->timestamps()
 *     ->create()
 *     ->table('posts')
 *     ->id()
 *     ->foreignId('user_id')->constrained('users')
 *     ->string('title')
 *     ->text('content')
 *     ->timestamps()
 *     ->create();
 * ```
 */
class SchemaBuilder implements SchemaBuilderInterface
{
    /** @var Connection Database connection */
    private Connection $connection;

    /** @var SqlGeneratorInterface SQL generator for database-specific queries */
    private SqlGeneratorInterface $sqlGenerator;

    /** @var array<string> Pending SQL operations */
    private array $pendingOperations = [];

    /**
     * Create a new fluent schema builder
     *
     * @param Connection $connection Database connection
     * @param SqlGeneratorInterface $sqlGenerator Database-specific SQL generator
     */
    public function __construct(Connection $connection, SqlGeneratorInterface $sqlGenerator)
    {
        $this->connection = $connection;
        $this->sqlGenerator = $sqlGenerator;
    }

    /**
     * Start building a new table
     *
     * @param string $name Table name
     * @return TableBuilderInterface Fluent table builder
     */
    public function table(string $name): TableBuilderInterface
    {
        return new TableBuilder($this, $this->sqlGenerator, $name, false);
    }

    /**
     * Create a new table
     *
     * When called with only a name, returns a fluent table builder (alias for table())
     * When called with a callback, creates the table immediately and auto-executes
     *
     * @param string $name Table name
     * @param callable|null $callback Optional table definition callback
     * @return TableBuilderInterface|self Fluent table builder or self for chaining
     * @throws \RuntimeException If table creation fails
     */
    public function createTable(string $name, ?callable $callback = null): TableBuilderInterface|self
    {
        if ($callback === null) {
            // Legacy behavior - return fluent builder
            return $this->table($name);
        }

        // New behavior - create table with callback and execute immediately
        $tableBuilder = $this->table($name);
        $callback($tableBuilder);
        $tableBuilder->create();

        // Always execute immediately - following the old schema manager pattern
        $this->execute();

        return $this;
    }

    /**
     * Alter an existing table
     *
     * @param string $name Table name
     * @return TableBuilderInterface Fluent table builder for alterations
     */
    public function alterTable(string $name): TableBuilderInterface
    {
        return new TableBuilder($this, $this->sqlGenerator, $name, true);
    }

    /**
     * Drop a table
     *
     * @param string $name Table name
     * @return self For method chaining
     */
    public function dropTable(string $name): self
    {
        $sql = $this->sqlGenerator->dropTable($name);
        $this->addPendingOperation($sql);
        return $this;
    }

    /**
     * Drop a table if it exists
     *
     * @param string $name Table name
     * @return self For method chaining
     */
    public function dropTableIfExists(string $name): self
    {
        $sql = $this->sqlGenerator->dropTable($name, true);
        $this->addPendingOperation($sql);
        // Execute immediately like createTable
        $this->execute();
        return $this;
    }

    /**
     * Create a database
     *
     * @param string $name Database name
     * @return self For method chaining
     */
    public function createDatabase(string $name): self
    {
        $sql = $this->sqlGenerator->createDatabase($name);
        $this->addPendingOperation($sql);
        return $this;
    }

    /**
     * Drop a database
     *
     * @param string $name Database name
     * @return self For method chaining
     */
    public function dropDatabase(string $name): self
    {
        $sql = $this->sqlGenerator->dropDatabase($name);
        $this->addPendingOperation($sql);
        return $this;
    }

    /**
     * Execute all pending operations within a transaction
     *
     * For DDL operations like CREATE TABLE, MySQL automatically commits transactions,
     * so we execute operations directly without transaction management.
     * This follows the pattern of the original MySQL schema manager.
     *
     * @param callable $callback Operations to execute
     * @return self For method chaining
     * @throws \RuntimeException If execution fails
     */
    public function transaction(callable $callback): self
    {

        try {
            // Execute the callback - operations will be executed immediately
            $callback($this);
        } catch (\Exception $e) {
            error_log("SchemaBuilder Callback failed: " . $e->getMessage());
            $this->reset();
            throw new \RuntimeException("Schema operation failed: {$e->getMessage()}", 0, $e);
        }

        return $this;
    }

    /**
     * Execute all pending schema operations
     *
     * @return array Results of executed operations
     * @throws \RuntimeException If execution fails
     */
    public function execute(): array
    {

        if (empty($this->pendingOperations)) {
            return [];
        }

        $results = [];
        $executed = [];

        $pdo = $this->connection->getPDO();

        try {
            foreach ($this->pendingOperations as $i => $sql) {
                // Always use direct PDO exec for DDL statements
                // This is simpler and avoids transaction context mismatches
                $result = $this->connection->getPDO()->exec($sql);
                $results[] = $result;
                $executed[] = $sql;
            }

            $this->reset();
            return $results;
        } catch (\Exception $e) {
            // Log which operations were executed for debugging
            error_log("Schema execution failed after executing " . count($executed) . " operations");
            foreach ($executed as $i => $sql) {
                error_log("Executed [{$i}]: {$sql}");
            }

            throw new \RuntimeException(
                "Schema execution failed on operation " . (count($executed) + 1) . ": {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Preview what SQL would be executed without running it
     *
     * @return array Array of SQL statements that would be executed
     */
    public function preview(): array
    {
        return $this->pendingOperations;
    }

    /**
     * Validate all pending operations without executing
     *
     * @return array Validation results with errors and warnings
     */
    public function validate(): array
    {
        $results = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'operations' => count($this->pendingOperations)
        ];

        // Basic SQL validation
        foreach ($this->pendingOperations as $i => $sql) {
            // Check for empty operations
            if (empty(trim($sql))) {
                $results['errors'][] = "Operation {$i}: Empty SQL statement";
                $results['valid'] = false;
                continue;
            }

            // Check for potential dangerous operations
            if (preg_match('/DROP\s+DATABASE/i', $sql)) {
                $results['warnings'][] = "Operation {$i}: DROP DATABASE detected - potentially destructive";
            }

            if (preg_match('/DROP\s+TABLE/i', $sql)) {
                $results['warnings'][] = "Operation {$i}: DROP TABLE detected - data loss possible";
            }

            // Check for basic SQL syntax issues
            if (!preg_match('/;\s*$/', trim($sql))) {
                $results['warnings'][] = "Operation {$i}: Missing semicolon terminator";
            }
        }

        return $results;
    }


    /**
     * Clear all pending operations
     *
     * @return self For method chaining
     */
    public function reset(): self
    {
        $this->pendingOperations = [];
        return $this;
    }

    /**
     * Check if a table exists
     *
     * @param string $table Table name
     * @return bool True if table exists
     */
    public function hasTable(string $table): bool
    {
        $sql = $this->sqlGenerator->tableExistsQuery($table);
        $stmt = $this->connection->getPDO()->query($sql);
        $result = $stmt->fetchColumn();
        return (bool) $result;
    }

    /**
     * Check if a column exists in a table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return bool True if column exists
     */
    public function hasColumn(string $table, string $column): bool
    {
        $sql = $this->sqlGenerator->columnExistsQuery($table, $column);
        $stmt = $this->connection->getPDO()->query($sql);
        $result = $stmt->fetchColumn();
        return (bool) $result;
    }

    /**
     * Get list of all tables
     *
     * @return array Array of table names
     */
    public function getTables(): array
    {
        $sql = $this->sqlGenerator->getTablesQuery();
        $stmt = $this->connection->getPDO()->query($sql);
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return $result;
    }

    /**
     * Get complete table schema information
     *
     * @param string $table Table name
     * @return array Complete schema information
     */
    public function getTableSchema(string $table): array
    {
        $sql = $this->sqlGenerator->getTableSchemaQuery($table);
        $stmt = $this->connection->getPDO()->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get table columns information
     *
     * @param string $table Table name
     * @return array Array of column definitions with 'name' field
     */
    public function getTableColumns(string $table): array
    {
        // Use the SQL generator's database-specific implementation
        return $this->sqlGenerator->getTableColumns($table, $this->connection->getPDO());
    }

    /**
     * Disable foreign key checks
     *
     * @return self For method chaining
     */
    public function disableForeignKeyChecks(): self
    {
        $sql = $this->sqlGenerator->foreignKeyChecks(false);
        $this->connection->getPDO()->exec($sql);
        return $this;
    }

    /**
     * Enable foreign key checks
     *
     * @return self For method chaining
     */
    public function enableForeignKeyChecks(): self
    {
        $sql = $this->sqlGenerator->foreignKeyChecks(true);
        $this->connection->getPDO()->exec($sql);
        return $this;
    }

    /**
     * Add a pending SQL operation
     *
     * @param string $sql SQL statement to add
     * @return void
     */
    public function addPendingOperation(string $sql): void
    {
        $this->pendingOperations[] = $sql;
    }

    /**
     * Get the database connection
     *
     * @return Connection Database connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the SQL generator
     *
     * @return SqlGeneratorInterface SQL generator
     */
    public function getSqlGenerator(): SqlGeneratorInterface
    {
        return $this->sqlGenerator;
    }

    /**
     * Get the size of a table in bytes
     *
     * @param string $table Table name
     * @return int Table size in bytes
     */
    public function getTableSize(string $table): int
    {
        try {
            $sql = $this->sqlGenerator->getTableSizeQuery($table);
            $stmt = $this->connection->getPDO()->query($sql);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int) ($result['size'] ?? 0);
        } catch (\Exception $e) {
            error_log("Failed to get table size for {$table}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the number of rows in a table
     *
     * @param string $table Table name
     * @return int Number of rows
     */
    public function getTableRowCount(string $table): int
    {
        try {
            $sql = $this->sqlGenerator->getTableRowCountQuery($table);
            $stmt = $this->connection->getPDO()->query($sql);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int) ($result['count'] ?? 0);
        } catch (\Exception $e) {
            error_log("Failed to get row count for {$table}: " . $e->getMessage());
            return 0;
        }
    }

    // ===========================================
    // Convenience Methods for Backward Compatibility
    // ===========================================

    /**
     * Add a column to an existing table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param array $definition Column definition
     * @return array Result with success status
     */
    public function addColumn(string $table, string $column, array $definition): array
    {
        try {
            $tableBuilder = $this->alterTable($table);

            // Map the definition to fluent API calls
            $columnBuilder = $tableBuilder->addColumn($column, $definition['type'] ?? 'string');

            if (isset($definition['nullable']) && !$definition['nullable']) {
                $columnBuilder->notNull();
            }
            if (isset($definition['default'])) {
                $columnBuilder->default($definition['default']);
            }

            $tableBuilder->execute();

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Drop a column from a table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return array Result with success status
     */
    public function dropColumn(string $table, string $column): array
    {
        try {
            $this->alterTable($table)->dropColumn($column)->execute();
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add an index to a table
     *
     * @param array $indexes Index definitions
     * @return self For method chaining
     */
    public function addIndex(array $indexes): self
    {
        try {
            foreach ($indexes as $index) {
                $table = $index['table'];
                $column = $index['column'];
                $type = $index['type'] ?? 'index';

                $tableBuilder = $this->alterTable($table);

                if ($type === 'unique') {
                    $tableBuilder->unique($column, $index['name'] ?? null);
                } else {
                    $tableBuilder->index($column, $index['name'] ?? null);
                }

                $tableBuilder->execute();
            }
        } catch (\Exception $e) {
            error_log("Failed to add index: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Drop an index from a table
     *
     * @param string $table Table name
     * @param string $index Index name
     * @return bool Success status
     */
    public function dropIndex(string $table, string $index): bool
    {
        try {
            $this->alterTable($table)->dropIndex($index)->execute();
            return true;
        } catch (\Exception $e) {
            error_log("Failed to drop index: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add foreign key constraints
     *
     * @param array $foreignKeys Foreign key definitions
     * @return self For method chaining
     */
    public function addForeignKey(array $foreignKeys): self
    {
        try {
            foreach ($foreignKeys as $fk) {
                $table = $fk['table'];
                $column = $fk['column'];
                $references = $fk['references'] ?? $fk['reference_column'];
                $on = $fk['on'] ?? $fk['reference_table'];
                $name = $fk['name'] ?? null;

                $this->alterTable($table)
                    ->foreign($column)
                    ->references($references)
                    ->on($on)
                    ->execute();
            }
        } catch (\Exception $e) {
            error_log("Failed to add foreign key: " . $e->getMessage());
        }

        return $this;
    }

    /**
     * Drop a foreign key constraint
     *
     * @param string $table Table name
     * @param string $constraint Constraint name
     * @return bool Success status
     */
    public function dropForeignKey(string $table, string $constraint): bool
    {
        try {
            $this->alterTable($table)->dropForeign($constraint)->execute();
            return true;
        } catch (\Exception $e) {
            error_log("Failed to drop foreign key: " . $e->getMessage());
            return false;
        }
    }

    // ===========================================
    // Advanced Schema Management Methods (Delegated to SQL Generators)
    // ===========================================

    /**
     * Generate preview of schema changes
     *
     * @param string $table Table name
     * @param array $changes Changes to preview
     * @return array Preview information
     */
    public function generateChangePreview(string $table, array $changes): array
    {
        return $this->sqlGenerator->generateChangePreview($table, $changes);
    }

    /**
     * Export table schema in specified format
     *
     * @param string $table Table name
     * @param string $format Export format
     * @return array Exported schema
     */
    public function exportTableSchema(string $table, string $format): array
    {
        $schema = $this->getTableSchema($table);
        return $this->sqlGenerator->exportTableSchema($table, $format, $schema);
    }

    /**
     * Validate schema definition
     *
     * @param array $schema Schema to validate
     * @param string $format Schema format
     * @return array Validation result
     */
    public function validateSchema(array $schema, string $format): array
    {
        return $this->sqlGenerator->validateSchema($schema, $format);
    }

    /**
     * Import table schema from definition
     *
     * @param string $table Table name
     * @param array $schema Schema definition
     * @param string $format Schema format
     * @param array $options Import options
     * @return array Import result
     */
    public function importTableSchema(string $table, array $schema, string $format, array $options): array
    {
        // Delegate to SQL generator for database-specific import logic
        $result = $this->sqlGenerator->importTableSchema($table, $schema, $format, $options);

        if ($result['success'] && !empty($result['sql'])) {
            try {
                // Execute the SQL statements generated by the SQL generator
                foreach ($result['sql'] as $sql) {
                    $this->connection->getPDO()->exec($sql);
                }

                $result['message'] = 'Schema imported and executed successfully';
                $result['executed'] = true;
            } catch (\Exception $e) {
                $result['success'] = false;
                $result['error'] = $e->getMessage();
                $result['message'] = 'Schema import SQL execution failed';
                $result['executed'] = false;
            }
        }

        return $result;
    }

    /**
     * Generate revert operations for a change
     *
     * @param array $change Original change
     * @return array Revert operations
     */
    public function generateRevertOperations(array $change): array
    {
        return $this->sqlGenerator->generateRevertOperations($change);
    }

    /**
     * Execute revert operations
     *
     * @param array $operations Revert operations
     * @return array Execution result
     */
    public function executeRevert(array $operations): array
    {
        $results = [];
        $success = true;

        foreach ($operations as $op) {
            try {
                switch ($op['type']) {
                    case 'drop_column':
                        $this->alterTable($op['table'])->dropColumn($op['column_name'])->execute();
                        $results[] = "Dropped column {$op['column_name']} from {$op['table']}";
                        break;

                    case 'add_column':
                        $this->alterTable($op['table'])
                            ->addColumn($op['column_name'], 'string')
                            ->execute();
                        $results[] = "Added column {$op['column_name']} to {$op['table']}";
                        break;

                    default:
                        $results[] = "Skipped unknown operation: {$op['type']}";
                }
            } catch (\Exception $e) {
                $success = false;
                $results[] = "Failed: " . $e->getMessage();
            }
        }

        return [
            'success' => $success,
            'operations' => count($operations),
            'results' => $results
        ];
    }
}
