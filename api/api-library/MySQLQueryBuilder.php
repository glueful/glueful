<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

use Mapi\Api\Library\{QueryAction, FilterOperator};

class MySQLQueryBuilder 
{
    private const RESERVED_PARAMS = [
        'fields', 'orderby', 'token', 'filter', 
        'limit', 'offset', 'dbres', 'fmt'
    ];

    public static function prepare(
        QueryAction $action, 
        array $definition, 
        ?array $params = null,
        ?array $config = null,
        ?\Mapi\Api\Http\Pagination $pagination = null
    ): string {
        $table = $definition['table']['name'];
        $fields = array_map(
            fn($field) => $field['name'],
            $definition['table']['fields']
        );

        $query = match($action) {
            QueryAction::SELECT => self::buildSelectQuery($table, $fields, $params),
            QueryAction::INSERT => self::buildInsertQuery($table, $params),
            QueryAction::UPDATE => self::buildUpdateQuery($table, $params),
            QueryAction::DELETE => self::buildDeleteQuery($table, $params),
            default => throw new \InvalidArgumentException("Unsupported query action: {$action->value}")
        };

        if ($pagination && $action === QueryAction::SELECT) {
            $query .= " LIMIT {$pagination->getLimit()} OFFSET {$pagination->getOffset()}";
        }

        return $query;
    }

    private static function buildSelectQuery(string $table, array $fields, ?array $params): string {
        $selectedFields = '*';
        if (isset($params['fields'])) {
            $selectedFields = $params['fields'];
            unset($params['fields']);
        }

        $query = "SELECT $selectedFields FROM $table";

        if (!empty($params)) {
            $conditions = [];
            foreach ($params as $field => $value) {
                if ($field === '_filter') continue;
                if (in_array($field, self::RESERVED_PARAMS)) continue;
                $conditions[] = "$field = " . self::quote($value);
            }
            if (!empty($conditions)) {
                $query .= " WHERE " . implode(' AND ', $conditions);
            }
        }

        if (isset($params['orderby'])) {
            $query .= " ORDER BY {$params['orderby']}";
        }

        return $query;
    }

    private static function buildInsertQuery(string $table, ?array $params): string {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for INSERT query");
        }

        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        $fields = array_keys($filteredParams);
        $values = array_map([self::class, 'quote'], array_values($filteredParams));

        return sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $fields),
            implode(', ', $values)
        );
    }

    private static function buildUpdateQuery(string $table, ?array $params): string {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for UPDATE query");
        }

        $id = $params['id'] ?? null;
        unset($params['id']);
        
        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        $setParts = array_map(
            fn($field) => "$field = " . self::quote($filteredParams[$field]),
            array_keys($filteredParams)
        );

        return sprintf(
            "UPDATE %s SET %s WHERE id = %s",
            $table,
            implode(', ', $setParts),
            self::quote($id)
        );
    }

    private static function buildDeleteQuery(string $table, ?array $params): string {
        if (!isset($params['id'])) {
            throw new \InvalidArgumentException("No ID provided for DELETE query");
        }

        return sprintf(
            "DELETE FROM %s WHERE id = %s",
            $table,
            self::quote($params['id'])
        );
    }

    private static function quote($value): string {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . addslashes($value) . "'";
    }

    public static function query(string $query): mixed {
        global $databaseResource;
        
        // Use current database resource or fall back to primary
        $dbIndex = $databaseResource ?? 'primary';
        
        try {
            $resource = Utils::getMySQLResource($dbIndex);
            $result = $resource->query($query);
            
            if ($result === false) {
                throw new \RuntimeException("Query failed: " . $resource->error);
            }
            
            if ($result === true) {
                // For INSERT queries, return last insert ID
                return ['id' => $resource->insert_id];
            }
            
            // For SELECT queries, fetch all results
            return $result->fetch_all(MYSQLI_ASSOC);
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Database query failed: " . $e->getMessage());
        }
    }
}
?>
