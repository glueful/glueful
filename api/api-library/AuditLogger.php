<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\QueryAction;
use Glueful\Api\Http\Response;

/**
 * Audit Logger System
 * 
 * Handles logging and retrieval of system changes for audit purposes.
 * Tracks database modifications, user actions, and system events.
 */
class AuditLogger 
{
    /** @var \PDO|null Database connection */
    private static ?\PDO $db = null;
    
    /** @var string|null Query builder class name */
    private static ?string $queryBuilderClass = null;

    /**
     * Initialize Audit Logger
     * 
     * Sets up database connection and query builder.
     * 
     * @param string|null $queryBuilderClass Optional custom query builder
     */
    public static function initialize(string $queryBuilderClass = null): void 
    {
        self::$db = Utils::getMySQLConnection(config('database.primary'));
        self::$queryBuilderClass = $queryBuilderClass ?? MySQLQueryBuilder::class;
    }

    /**
     * Get audit definition
     * 
     * Loads and validates audit table JSON definition.
     * 
     * @return array Table definition
     * @throws \RuntimeException If definition is missing or invalid
     */
    private static function getDefinition(): array 
    {
        $dbConfig = config('database');
        $currentResource = array_key_first(array_filter($dbConfig, 'is_array'));
        $path = config('paths.json_definitions') . $currentResource .'.audit_logs.json';
        
        if (!file_exists($path)) {
            throw new \RuntimeException(
                "The audit_logs definition does not exist: $path",
                Response::HTTP_NOT_FOUND
            );
        }

        $definition = json_decode(file_get_contents($path), true);
        if (!$definition) {
            throw new \RuntimeException("Invalid JSON definition for audit_logs");
        }

        return $definition;
    }

    /**
     * Log audit event
     * 
     * Records a system change in the audit log.
     * 
     * @param string $action Type of action performed
     * @param string $table Affected table name
     * @param string|null $recordUuid Affected record UUID
     * @param array $changes Change details
     * @param string|null $userUuid User who made the change
     * @param string|null $sessionToken Active session token
     * @return bool True if logged successfully
     */
    public static function log(
        string $action,
        string $table,
        ?string $recordUuid,
        array $changes,
        ?string $userUuid = null,
        ?string $sessionToken = null
    ): bool 
    {
        try {
            $definition = self::getDefinition();
            $params = [
                'action' => $action,
                'table_name' => $table,
                'record_uuid' => $recordUuid,
                'changes' => json_encode($changes),
                'user_uuid' => $userUuid,
                'session_token' => $sessionToken,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $builder = self::$queryBuilderClass;
            $query = $builder::prepare(QueryAction::INSERT, $definition, $params);
            
            $stmt = self::$db->prepare($query['sql']);
            return $stmt->execute($query['params']);

        } catch (\PDOException $e) {
            error_log("Audit logging failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get audit trail
     * 
     * Retrieves audit history for table or specific record.
     * 
     * @param string $tableOrUuid Table name or record UUID
     * @param string|null $recordUuid Optional specific record UUID
     * @return array Audit history entries
     */
    public static function getAuditTrail(string $tableOrUuid, ?string $recordUuid = null): array 
    {
        try {
            $definition = self::getDefinition();
            $params = [
                'fields' => '*',
                '_filter' => $recordUuid ? [
                    ['field' => 'table_name', 'operator' => 'eq', 'value' => $tableOrUuid],
                    ['field' => 'record_uuid', 'operator' => 'eq', 'value' => $recordUuid]
                ] : [
                    ['field' => 'uuid', 'operator' => 'eq', 'value' => $tableOrUuid]
                ],
                '_sort' => ['created_at' => 'DESC']
            ];

            $builder = self::$queryBuilderClass;
            $query = $builder::prepare(QueryAction::fromString('list'), $definition, $params);
            
            $stmt = self::$db->prepare($query['sql']);
            $stmt->execute($query['params']);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("Audit trail retrieval failed: " . $e->getMessage());
            return [];
        }
    }
}
