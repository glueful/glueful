<?php

namespace Glueful\Database;

use PDO;
use PDOException;
use Exception;
use Glueful\Database\Driver\DatabaseDriver;

class QueryBuilder
{
    protected PDO $pdo;
    protected DatabaseDriver $driver;
    protected int $transactionLevel = 0;
    protected int $maxRetries = 3;
    protected bool $softDeletes = true;
    protected array $groupBy = [];
    protected array $orderBy = [];

    public function __construct(PDO $pdo, DatabaseDriver $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public function transaction(callable $callback)
    {
        $retryCount = 0;

        while ($retryCount < $this->maxRetries) {
            $this->beginTransaction();
            try {
                $result = $callback($this);
                $this->commit();
                return $result;
            } catch (Exception $e) {
                if ($this->isDeadlock($e)) {
                    $this->rollback();
                    $retryCount++;
                    usleep(500000);
                } else {
                    $this->rollback();
                    throw $e;
                }
            }
        }
        throw new Exception("Transaction failed after {$this->maxRetries} retries due to deadlock.");
    }

    protected function isDeadlock(Exception $e): bool
    {
        return in_array($e->getCode(), ['1213', '40001']);
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo->commit();
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    public function rollback(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    public function insert(string $table, array $data): int
    {
        $keys = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $columns = implode(', ', array_map([$this->driver, 'wrapIdentifier'], $keys));
        
        $sql = "INSERT INTO {$this->driver->wrapIdentifier($table)} ($columns) VALUES ($placeholders)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array_values($data)) ? $stmt->rowCount() : 0;
    }
    
    public function upsert(string $table, array $data, array $updateColumns): int
    {
        if (empty($data)) {
            return 0;
        }

        $keys = array_keys($data[0]);
        $sql = $this->driver->upsert($table, $keys, $updateColumns);
        $stmt = $this->pdo->prepare($sql);

        $insertCount = 0;
        foreach ($data as $row) {
            if ($stmt->execute(array_values($row))) {
                $insertCount++;
            }
        }

        return $insertCount;
    }

    public function select(string $table, array $columns = ['*'], array $conditions = [], bool $withTrashed = false): array
    {
        $columnList = implode(", ", array_map([$this->driver, 'wrapIdentifier'], $columns));
        $sql = "SELECT $columnList FROM " . $this->driver->wrapIdentifier($table);

        $whereClauses = [];
        foreach ($conditions as $col => $value) {
            $whereClauses[] = "{$this->driver->wrapIdentifier($col)} = ?";
        }

        if ($this->softDeletes && !$withTrashed) {
            $whereClauses[] = "deleted_at IS NULL";
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        return $this->rawQuery($sql, array_values($conditions));
    }

    public function delete(string $table, array $conditions, bool $softDelete = true): bool
    {
        $sql = $softDelete ? 
            "UPDATE " . $this->driver->wrapIdentifier($table) . " SET deleted_at = CURRENT_TIMESTAMP WHERE " :
            "DELETE FROM " . $this->driver->wrapIdentifier($table) . " WHERE ";

        $sql .= implode(" AND ", array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions)));

        return $this->executeQuery($sql, array_values($conditions))->rowCount() > 0;
    }

    public function restore(string $table, array $conditions): bool
    {
        $sql = "UPDATE " . $this->driver->wrapIdentifier($table) . " SET deleted_at = NULL WHERE " .
               implode(" AND ", array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions)));
        return $this->executeQuery($sql, array_values($conditions))->rowCount() > 0;
    }

    public function count(string $table, array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) FROM " . $this->driver->wrapIdentifier($table);
        if (!empty($conditions)) {
            $whereClauses = array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions));
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $stmt = $this->executeQuery($sql, array_values($conditions));
        return (int) $stmt->fetchColumn();
    }

    public function rawQuery(string $sql, array $params = []): array
    {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function executeQuery(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function paginate(string $table, array $columns = ['*'], array $conditions = [], int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;
        $columnList = implode(", ", array_map([$this->driver, 'wrapIdentifier'], $columns));
        $sql = "SELECT $columnList FROM " . $this->driver->wrapIdentifier($table);

        if (!empty($conditions)) {
            $whereClauses = array_map(fn($col) => "{$this->driver->wrapIdentifier($col)} = ?", array_keys($conditions));
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...array_values($conditions), $perPage, $offset]);

        $totalRecords = $this->count($table, $conditions);
        $lastPage = (int) ceil($totalRecords / $perPage);
        $from = $totalRecords > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $totalRecords);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalRecords,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
            'from' => $from,
            'to' => $to,
        ];
    }
    public function lastInsertId(string $table, string $column = 'uuid'): string 
    {
        $result = $this->select(
            $table,
            [$column],
            ['id' => $this->rawQuery('SELECT LAST_INSERT_ID()')[0]['LAST_INSERT_ID()']]
        );

        if (empty($result) || !isset($result[0][$column])) {
            throw new \RuntimeException("Failed to retrieve $column for new record");
        }

        return $result[0][$column];
    }
}
