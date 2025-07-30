<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\RequestHelper;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class MigrationsController extends BaseController
{
    private Connection $db;
    private MigrationManager $migrationManager;

    public function __construct(
        ?Connection $connection = null,
        ?MigrationManager $migrationManager = null,
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?SymfonyRequest $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $request);

        // Initialize dependencies with dependency injection
        $this->db = $connection ?? new Connection();
        $this->migrationManager = $migrationManager ?? new MigrationManager();
    }

    /**
     * Get all database migrations with status
     *
     * @return mixed HTTP response
     */
    public function getMigrations(): mixed
    {
        // Check permission to view migrations
        $this->requirePermission('system.migrations.view');

        // Apply rate limiting for migration list access (100 attempts per hour)
        $this->rateLimit('getMigrations', 100, 3600);

        $data = RequestHelper::getRequestData();

        // Set default values for pagination and filtering
        $page = (int)($data['page'] ?? 1);
        $perPage = (int)($data['per_page'] ?? 25);

        // Use permission-aware caching for migrations list
        return $this->cacheByPermission(
            "migrations_list_page_{$page}_per_{$perPage}",
            function () use ($page, $perPage) {

                // Get migrations from schema manager
                $results = $this->db->table('migrations')
                    ->select([
                        'migrations.id',
                        'migrations.migration',
                        'migrations.batch',
                        'migrations.applied_at',
                        'migrations.checksum',
                        'migrations.description'
                    ])
                    ->orderBy('applied_at', 'DESC')
                    ->paginate($page, $perPage);

                $data =  $results['data'] ?? [];
                $meta = $results;
                unset($meta['data']); // Remove data from meta
                return Response::successWithMeta($data, $meta, 'Migrations retrieved successfully');
            },
            600  // 10 minute cache for migrations list
        );
    }

    /**
     * Get pending migrations data without sending response (for internal use)
     *
     * @return array Pending migrations data
     */
    public function getPendingMigrationsData(): array
    {
        // Get all available migration files
        $pendingMigrations = $this->migrationManager->getPendingMigrations();

        // Format the response data
        $formattedMigrations = array_map(function ($migration) {
            return [
                'name' => basename($migration),
                'status' => 'pending',
                'migration_file' => $migration
            ];
        }, $pendingMigrations);

        return [
            'pending_count' => count($pendingMigrations),
            'migrations' => $formattedMigrations
        ];
    }

    /**
     * Get pending migrations that haven't been executed
     *
     * @return mixed HTTP response
     */
    public function getPendingMigrations(): mixed
    {
        // Check permission to view migrations
        $this->requirePermission('system.migrations.view');

        // Apply rate limiting for pending migrations access (50 attempts per hour)
        $this->rateLimit('getPendingMigrations', 50, 3600);

        // Use permission-aware caching for pending migrations
        return $this->cacheByPermission(
            'pending_migrations',
            function () {

                // Use the new data method to get pending migrations
                $data = $this->getPendingMigrationsData();


                return Response::success($data, 'Pending migrations retrieved successfully');
            },
            300  // 5 minute cache for pending migrations
        );
    }
}
