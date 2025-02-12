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
    ): array {
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
            $query['sql'] .= " LIMIT :limit OFFSET :offset";
            $query['params'][':limit'] = $pagination->getLimit();
            $query['params'][':offset'] = $pagination->getOffset();
        }

        return $query;
    }

    public static function query(array $queryData): mixed 
    {
        global $databaseResource;
        
        // Use current database resource or fall back to primary
        $dbIndex = $databaseResource ?? 'primary';
        
        try {
            $db = Utils::getMySQLConnection($dbIndex);
            $stmt = $db->prepare($queryData['sql']);
            
            // Bind parameters if they exist
            if (isset($queryData['params'])) {
                foreach ($queryData['params'] as $param => $value) {
                    $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                    $stmt->bindValue($param, $value, $type);
                }
            }
            
            $stmt->execute();
            
            // For SELECT queries
            if (stripos($queryData['sql'], 'SELECT') === 0) {
                return $stmt->fetchAll();
            }
            
            // For INSERT queries
            if (stripos($queryData['sql'], 'INSERT') === 0) {
                return ['id' => $db->lastInsertId()];
            }
            
            // For UPDATE/DELETE queries
            return ['affected' => $stmt->rowCount()];
            
        } catch (\PDOException $e) {
            throw new \RuntimeException("Database query failed: " . $e->getMessage());
        }
    }

    private static function buildSelectQuery(string $table, array $fields, ?array $params): array {
        $selectedFields = '*';
        if (isset($params['fields'])) {
            $selectedFields = $params['fields'];
            unset($params['fields']);
        }

        $sql = "SELECT $selectedFields FROM $table";
        $queryParams = [];

        if (!empty($params)) {
            $conditions = [];
            foreach ($params as $field => $value) {
                if ($field === '_filter' || in_array($field, self::RESERVED_PARAMS)) continue;
                $paramName = ":$field";
                $conditions[] = "$field = $paramName";
                $queryParams[$paramName] = $value;
            }
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
        }

        if (isset($params['orderby'])) {
            $sql .= " ORDER BY {$params['orderby']}";
        }

        return ['sql' => $sql, 'params' => $queryParams];
    }

    private static function buildInsertQuery(string $table, ?array $params): array {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for INSERT query");
        }

        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        $fields = array_keys($filteredParams);
        $paramNames = array_map(fn($field) => ":$field", $fields);
        $queryParams = array_combine($paramNames, array_values($filteredParams));

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $fields),
            implode(', ', $paramNames)
        );

        return ['sql' => $sql, 'params' => $queryParams];
    }

    private static function buildUpdateQuery(string $table, ?array $params): array {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for UPDATE query");
        }

        $id = $params['id'] ?? null;
        unset($params['id']);
        
        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        $setParts = array_map(
            fn($field) => "$field = :$field",
            array_keys($filteredParams)
        );

        $sql = sprintf(
            "UPDATE %s SET %s WHERE id = :id",
            $table,
            implode(', ', $setParts)
        );

        $queryParams = array_combine(
            array_map(fn($field) => ":$field", array_keys($filteredParams)),
            array_values($filteredParams)
        );
        $queryParams[':id'] = $id;

        return ['sql' => $sql, 'params' => $queryParams];
    }

    private static function buildDeleteQuery(string $table, ?array $params): array {
        if (!isset($params['id'])) {
            throw new \InvalidArgumentException("No ID provided for DELETE query");
        }

        return [
            'sql' => "DELETE FROM $table WHERE id = :id",
            'params' => [':id' => $params['id']]
        ];
    }
}
?>
