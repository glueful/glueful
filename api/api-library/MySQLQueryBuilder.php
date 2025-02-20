<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\{QueryAction, FilterOperator};
/**
 * MySQL Query Builder
 * 
 * Handles construction and execution of MySQL queries with support for:
 * - CRUD operations (SELECT, INSERT, UPDATE, DELETE)
 * - Parameter binding for SQL injection prevention
 * - Dynamic filtering and pagination
 * - UUID support for records
 * - Resource validation
 */

class MySQLQueryBuilder 
{
    /**
     * Parameters that receive special handling and shouldn't be used in WHERE clauses
     * @var array<string>
     */
    private const RESERVED_PARAMS = [
        'fields', 'orderby', 'token', 'filter', 
        'limit', 'offset', 'dbres', 'fmt'
    ];

     /**
     * Prepare a database query for execution
     * 
     * Builds appropriate SQL query based on action type and handles pagination.
     * 
     * @param QueryAction $action The type of query to build
     * @param array $definition Table/resource definition
     * @param array|null $params Query parameters and filters
     * @param array|null $config Additional configuration
     * @param \Glueful\Api\Http\Pagination|null $pagination Pagination settings
     * @return array{sql: string, params: array} Prepared query with bound parameters
     * @throws \InvalidArgumentException If action is not supported
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
     * Execute a prepared query
     * 
     * Handles parameter binding and returns appropriate result format based on query type.
     * 
     * @param array{sql: string, params: array} $queryData The prepared query
     * @return mixed Query results (array for SELECT, affected rows for others)
     * @throws \RuntimeException On database errors
     */

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

     /**
     * Build SELECT query with filtering and sorting
     * 
     * @param string $table Target table
     * @param array $fields Available fields
     * @param array|null $params Query parameters
     * @return array{sql: string, params: array} Prepared SELECT query
     */

    private static function buildSelectQuery(string $table, array $fields, ?array $params): array {
        $selectedFields = '*';
        if (isset($params['fields'])) {
            $selectedFields = $params['fields'];
            unset($params['fields']);
        }

        $sql = "SELECT $selectedFields FROM $table";
        $queryParams = [];
        $conditions = [];

        // Handle _filter parameter
        if (isset($params['_filter'])) {
            $filterResult = self::parseFilters($params['_filter']);
            if (!empty($filterResult['conditions'])) {
                $conditions[] = $filterResult['conditions'];
            }
            $queryParams = array_merge($queryParams, $filterResult['params']);
            unset($params['_filter']);
        }

        // Handle regular field=value conditions
        foreach ($params as $field => $value) {
            if (in_array($field, self::RESERVED_PARAMS)) continue;
            $paramName = ":$field";
            
            // Support both id and uuid in conditions
            if ($field === 'id' && isset($params['uuid'])) {
                continue; // Skip id if uuid is present
            }
            
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
     * Build INSERT query with UUID generation
     * 
     * @param string $table Target table
     * @param array|null $params Data to insert
     * @return array{sql: string, params: array} Prepared INSERT query
     * @throws \InvalidArgumentException If params is empty
     */

    private static function buildInsertQuery(string $table, ?array $params): array {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for INSERT query");
        }

        $filteredParams = array_diff_key($params, array_flip(self::RESERVED_PARAMS));
        $fields = array_keys($filteredParams);
        
        // Always include uuid field with Utils::generateNanoID
        if (!in_array('uuid', $fields)) {
            $fields[] = 'uuid';
            $filteredParams['uuid'] = Utils::generateNanoID(12);
        }
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
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
     * Build UPDATE query supporting both ID and UUID
     * 
     * @param string $table Target table
     * @param array|null $params Update data and record identifier
     * @return array{sql: string, params: array} Prepared UPDATE query
     * @throws \InvalidArgumentException If required params missing
     */

    private static function buildUpdateQuery(string $table, ?array $params): array {
        if (empty($params)) {
            throw new \InvalidArgumentException("No parameters provided for UPDATE query");
        }

        // Support both id and uuid for updates
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
            "UPDATE %s SET %s WHERE %s = :%s",
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
     * Build DELETE query supporting both ID and UUID
     * 
     * @param string $table Target table
     * @param array|null $params Deletion criteria
     * @return array{sql: string, params: array} Prepared DELETE query
     * @throws \InvalidArgumentException If identifier missing
     */

    private static function buildDeleteQuery(string $table, ?array $params): array {
        // Support both id and uuid for deletes
        $identifier = isset($params['uuid']) ? 'uuid' : 'id';
        $identifierValue = $params[$identifier] ?? null;
        
        if (!$identifierValue) {
            throw new \InvalidArgumentException("No identifier (id or uuid) provided for DELETE query");
        }

        return [
            'sql' => "DELETE FROM $table WHERE $identifier = :$identifier",
            'params' => [":$identifier" => $identifierValue]
        ];
    }

    /**
     * Parse single filter condition into SQL
     * 
     * @param array{field: string, operator: string, value: mixed} $filter Filter definition
     * @param int $counter Parameter counter for unique names
     * @return array{condition: string, params: array}|null SQL condition or null if invalid
     */

    private static function parseSingleFilter(array $filter, int $counter): ?array {
        if (!isset($filter['field']) || !isset($filter['operator']) || !isset($filter['value'])) {
            return null;
        }

        $field = $filter['field'];
        $operator = FilterOperator::tryFrom($filter['operator']);
        $value = $filter['value'];
        $paramName = ":filter{$counter}";

        if (!$operator) {
            return null;
        }

        $condition = match($operator) {
            FilterOperator::EQUALS => "$field = $paramName",
            FilterOperator::NOT_EQUALS => "$field != $paramName",
            FilterOperator::GREATER_THAN => "$field > $paramName",
            FilterOperator::LESS_THAN => "$field < $paramName",
            FilterOperator::GREATER_EQUALS => "$field >= $paramName",
            FilterOperator::LESS_EQUALS => "$field <= $paramName",
            FilterOperator::LIKE => "$field LIKE $paramName",
            FilterOperator::NOT_LIKE => "$field NOT LIKE $paramName",
            FilterOperator::IN => "$field IN ($paramName)",
            FilterOperator::NOT_IN => "$field NOT IN ($paramName)",
        };

        return [
            'condition' => $condition,
            'params' => [$paramName => $value]
        ];
    }

    /**
     * Combine multiple filter conditions
     * 
     * @param array $filters Array of filter definitions
     * @return array{conditions: string, params: array} Combined SQL conditions
     */

    private static function parseFilters(array $filters): array 
    {
        $conditions = [];
        $params = [];
        $paramCounter = 1;

        foreach ($filters as $filter) {
            $result = self::parseSingleFilter($filter, $paramCounter++);
            if ($result) {
                $conditions[] = $result['condition'];
                $params = array_merge($params, $result['params']);
            }
        }

        return [
            'conditions' => implode(' AND ', $conditions),
            'params' => $params
        ];
    }

    /**
     * Convert string action to QueryAction enum
     * 
     * @param string $value Action name (e.g., 'list', 'insert', etc)
     * @return QueryAction The corresponding enum value
     * @throws \ValueError If action name is invalid
     */
    
    public static function fromString(string $value): QueryAction {
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
?>
