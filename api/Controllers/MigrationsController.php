<?php
declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Database\{Connection, QueryBuilder};
use Glueful\Database\Migrations\MigrationManager;

class MigrationsController {
    private QueryBuilder $queryBuilder;
    private MigrationManager $migrationManager;

    public function __construct() {
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $this->migrationManager = new MigrationManager();
    }

    /**
     * Get all database migrations with status
     * 
     * @return mixed HTTP response
     */
    public function getMigrations(): mixed
    {
        try {
            $data = Request::getPostData();
            
            // Set default values for pagination and filtering
            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['per_page'] ?? 25);
            
            // Get migrations from schema manager
            $results = $this->queryBuilder->select('migrations', [
                'migrations.id',
                'migrations.migration',
                'migrations.batch',
                'migrations.applied_at',
                'migrations.checksum',
                'migrations.description'
            ])
            ->orderBy(['applied_at' => 'DESC'])
            ->paginate($page, $perPage);

            return Response::ok($results, 'Migrations retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get migrations error: " . $e->getMessage());
            return Response::error(
                'Failed to get migrations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get pending migrations that haven't been executed
     * 
     * @return mixed HTTP response
     */
    public function getPendingMigrations(): mixed
    {
        try {
            // Get all available migration files
            $pendingMigrations = $this->migrationManager->getPendingMigrations();

            // Format the response data
            $formattedMigrations = array_map(function($migration) {
                return [
                    'name' => basename($migration),
                    'status' => 'pending',
                    'migration_file' => $migration
                ];
            }, $pendingMigrations);

            return Response::ok([
                'pending_count' => count($pendingMigrations),
                'migrations' => $formattedMigrations
            ], 'Pending migrations retrieved successfully')->send();

        } catch (\Exception $e) {
            error_log("Get pending migrations error: " . $e->getMessage());
            return Response::error(
                'Failed to get pending migrations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }
}