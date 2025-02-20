<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\{QueryAction, FilterOperator, Utils};
use Glueful\Api\Library\Interfaces\QueryBuilderInterface;

/**
 * SQLite Query Builder
 * 
 * Builds and executes SQLite-specific database queries.
 * Handles CRUD operations with SQLite features like RETURNING clause.
 */
class SQLiteQueryBuilder implements QueryBuilderInterface
{
    /** @var array Parameters that receive special handling */
    private const RESERVED_PARAMS = [
        'fields', 'orderby', 'token', 'filter', 
        'limit', 'offset', 'dbres', 'fmt'
    ];

    /**
     * Prepare database query
     * 
     * Builds SQLite-specific query based on action type.
     * 
     * @param QueryAction $action Query type (SELECT, INSERT, etc)
     * @param array $definition Table schema definition
     * @param array|null $params Query parameters
     * @param array|null $config Additional configuration
     * @param \Glueful\Api\Http\Pagination|null $pagination Pagination settings
     * @return array Query with SQL and parameters
     * @throws \InvalidArgumentException For unsupported actions
     */
    public static function prepare(
        QueryAction $action,
        array $definition,
        ?array $params = null,
        ?array $config = null,
        ?\Glueful\Api\Http\Pagination $pagination = null
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

    /**
     * Execute prepared query
     * 
     * Executes query with proper parameter binding.
     * 
     * @param array $queryData Query and parameters
     * @return mixed Query results
     * @throws \RuntimeException On execution failure
     */
    public static function query(array $queryData): mixed 
    {
        global $databaseResource;
        
        $dbIndex = $databaseResource ?? 'primary';
        
        try {
            $db = Utils::getSQLiteConnection($dbIndex);
            $stmt = $db->prepare($queryData['sql']);
            
            if (isset($queryData['params'])) {
                foreach ($queryData['params'] as $param => $value) {
                    $type = match(true) {
                        is_int($value) => \PDO::PARAM_INT,
                        is_bool($value) => \PDO::PARAM_BOOL,
                        is_null($value) => \PDO::PARAM_NULL,
                        default => \PDO::PARAM_STR
                    };
                    $stmt->bindValue($param, $value, $type);
                }
            }
            
            $stmt->execute();
            
            return match(true) {
                stripos($queryData['sql'], 'SELECT') === 0 => $stmt->fetchAll(\PDO::FETCH_ASSOC),
                stripos($queryData['sql'], 'INSERT') === 0 => ['id' => $db->lastInsertId()],
                default => ['affected' => $stmt->rowCount()]
            };
            
        } catch (\PDOException $e) {
            throw new \RuntimeException("SQLite query failed: " . $e->getMessage());
        }
    }

    /**
     * Build SELECT query
     * 
     * Creates SQLite SELECT query with conditions.
     * 
     * @param string $table Table name
     * @param array $fields Available fields
     * @param array|null $params Query parameters
     * @return array Query and parameters
     */
    private static function buildSelectQuery(string $table, array $fields, ?array $params): array 
    {
        $selectedFields = isset($params['fields']) ? $params['fields'] : '*';
        $sql = "SELECT $selectedFields FROM $table";
        $queryParams = [];
        $conditions = [];

        if (isset($params['_filter'])) {
            $filterResult = self::parseFilters($params['_filter']);
            if (!empty($filterResult['conditions'])) {
                $conditions[] = $filterResult['conditions'];
            }
            $queryParams = array_merge($queryParams, $filterResult['params']);
            unset($params['_filter']);
        }

        foreach ($params as $field => $value) {
            if (in_array($field, self::RESERVED_PARAMS)) continue;
            
            if ($field === 'id' && isset($params['uuid'])) continue;
            
            $paramName = ":$field";
            $conditions[] = "$field = $paramName";
            $queryParams[$paramName] = $value;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if (isset($params['orderby'])) {
            $sql .= " ORDER BY {$params['orderby']}";
        }

        return ['sql' => $sql, 'params' => $queryParams];
    }

    /**
     * Build INSERT query
     * 
     * Creates SQLite INSERT query with RETURNING clause.
     * 
     * @param string $table Table name
     * @param array|null $params Data to insert
     * @return array Query and parameters
     * @throws \InvalidArgumentException If params empty
     */
    private static function buildInsertQuery(string $table, ?array $params): array 
    {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for INSERT query");
        }

        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        
        if (!isset($filteredParams['uuid'])) {
            $filteredParams['uuid'] = Utils::generateNanoID(12);
        }
        
        $fields = array_keys($filteredParams);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s) RETURNING id, uuid",
            $table,
            implode(', ', $fields),
            implode(', ', array_map(fn($field) => ":$field", $fields))
        );

        $queryParams = array_combine(
            array_map(fn($field) => ":$field", array_keys($filteredParams)),
            array_values($filteredParams)
        );

        return ['sql' => $sql, 'params' => $queryParams];
    }

    /**
     * Build UPDATE query
     * 
     * Creates SQLite UPDATE query with RETURNING clause.
     * 
     * @param string $table Table name
     * @param array|null $params Update data
     * @return array Query and parameters
     * @throws \InvalidArgumentException If params invalid
     */
    private static function buildUpdateQuery(string $table, ?array $params): array 
    {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for UPDATE query");
        }

        $identifier = isset($params['uuid']) ? 'uuid' : 'id';
        $identifierValue = $params[$identifier] ?? null;
        
        if (!$identifierValue) {
            throw new \InvalidArgumentException("No identifier (id or uuid) provided for UPDATE query");
        }

        unset($params['id'], $params['uuid']);
        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        
        $setParts = array_map(
            fn($field) => "$field = :$field",
            array_keys($filteredParams)
        );

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s = :%s RETURNING *",
            $table,
            implode(', ', $setParts),
            $identifier,
            $identifier
        );

        $queryParams = array_combine(
            array_map(fn($field) => ":$field", array_keys($filteredParams)),
            array_values($filteredParams)
        );
        $queryParams[":$identifier"] = $identifierValue;

        return ['sql' => $sql, 'params' => $queryParams];
    }

    /**
     * Build DELETE query
     * 
     * Creates SQLite DELETE query with RETURNING clause.
     * 
     * @param string $table Table name
     * @param array|null $params Delete criteria
     * @return array Query and parameters
     * @throws \InvalidArgumentException If identifier missing
     */
    private static function buildDeleteQuery(string $table, ?array $params): array 
    {
        $identifier = isset($params['uuid']) ? 'uuid' : 'id';
        $identifierValue = $params[$identifier] ?? null;
        
        if (!$identifierValue) {
            throw new \InvalidArgumentException("No identifier (id or uuid) provided for DELETE query");
        }

        return [
            'sql' => "DELETE FROM $table WHERE $identifier = :$identifier RETURNING *",
            'params' => [":$identifier" => $identifierValue]
        ];
    }

    /**
     * Parse filter conditions
     * 
     * Converts filter array to SQLite conditions.
     * 
     * @param array $filters Filter definitions
     * @return array Conditions and parameters
     */
    private static function parseFilters(array $filters): array 
    {
        $conditions = [];
        $params = [];
        return [
            'conditions' => implode(' AND ', $conditions),
            'params' => $params
        ];
    }

    /**
     * Convert string to QueryAction
     * 
     * Maps common action names to enum values.
     * 
     * @param string $value Action name
     * @return QueryAction Corresponding enum
     * @throws \ValueError If action invalid
     */
    public static function fromString(string $value): QueryAction 
    {
        return match (strtolower($value)) {
            'list', 'view', 'select' => QueryAction::SELECT,
            'insert', 'create' => QueryAction::INSERT,
            'update', 'edit' => QueryAction::UPDATE,
            'delete', 'remove' => QueryAction::DELETE,
            'custom' => QueryAction::CUSTOM,
            default => throw new \ValueError("Invalid query action: {$value}")
        };
    }
}
