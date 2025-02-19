<?php
declare(strict_types=1);

namespace Glueful\Api\Library;

use Glueful\Api\Library\QueryAction;
use Glueful\Api\Http\Response;

class AuditLogger 
{
    private static ?\PDO $db = null;
    private static ?string $queryBuilderClass = null;

    public static function initialize(string $queryBuilderClass = null): void 
    {
        self::$db = Utils::getMySQLConnection(config('database.primary'));
        self::$queryBuilderClass = $queryBuilderClass ?? MySQLQueryBuilder::class;
    }

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

    public static function log(
        string $action,
        string $table,
        ?string $recordUuid,
        array $changes,
        ?string $userUuid = null,
        ?string $sessionToken = null
    ): bool {
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
