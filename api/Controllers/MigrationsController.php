<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\Request;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class MigrationsController extends BaseController
{
    private QueryBuilder $queryBuilder;
    private MigrationManager $migrationManager;

    public function __construct(
        ?QueryBuilder $queryBuilder = null,
        ?MigrationManager $migrationManager = null,
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?SymfonyRequest $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);

        // Initialize dependencies with dependency injection
        $this->queryBuilder = $queryBuilder ?? $this->getQueryBuilder();
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

        $data = Request::getPostData();

        // Set default values for pagination and filtering
        $page = (int)($data['page'] ?? 1);
        $perPage = (int)($data['per_page'] ?? 25);

        // Use permission-aware caching for migrations list
        return $this->cacheByPermission(
            "migrations_list_page_{$page}_per_{$perPage}",
            function () use ($page, $perPage) {
                // Enhanced audit logging with detailed metadata
                $this->auditLogger->audit(
                    AuditEvent::CATEGORY_SYSTEM,
                    'migration_list_accessed',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'controller' => static::class,
                        'action' => 'getMigrations',
                        'resource' => 'migrations',
                        'operation' => 'read',
                        'pagination' => [
                            'page' => $page,
                            'per_page' => $perPage,
                            'offset' => ($page - 1) * $perPage
                        ],
                        'request_metadata' => [
                            'method' => $this->request->getMethod(),
                            'content_type' => $this->request->headers->get('Content-Type'),
                            'accept' => $this->request->headers->get('Accept'),
                            'referer' => $this->request->headers->get('Referer')
                        ],
                        'security_context' => [
                            'ip_address' => $this->request->getClientIp(),
                            'user_agent' => $this->request->headers->get('User-Agent'),
                            'session_id' => session_id(),
                            'request_id' => $this->request->headers->get('X-Request-ID'),
                            'forwarded_for' => $this->request->headers->get('X-Forwarded-For')
                        ],
                        'performance' => [
                            'memory_usage' => memory_get_usage(true),
                            'peak_memory' => memory_get_peak_usage(true)
                        ],
                        'timestamp' => time(),
                        'iso_timestamp' => date('c')
                    ]
                );

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
                // Enhanced audit logging with detailed metadata
                $this->auditLogger->audit(
                    AuditEvent::CATEGORY_SYSTEM,
                    'pending_migrations_accessed',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'controller' => static::class,
                        'action' => 'getPendingMigrations',
                        'resource' => 'migrations',
                        'operation' => 'read_pending',
                        'request_metadata' => [
                            'method' => $this->request->getMethod(),
                            'content_type' => $this->request->headers->get('Content-Type'),
                            'accept' => $this->request->headers->get('Accept'),
                            'referer' => $this->request->headers->get('Referer')
                        ],
                        'security_context' => [
                            'ip_address' => $this->request->getClientIp(),
                            'user_agent' => $this->request->headers->get('User-Agent'),
                            'session_id' => session_id(),
                            'request_id' => $this->request->headers->get('X-Request-ID'),
                            'forwarded_for' => $this->request->headers->get('X-Forwarded-For')
                        ],
                        'performance' => [
                            'memory_usage' => memory_get_usage(true),
                            'peak_memory' => memory_get_peak_usage(true)
                        ],
                        'timestamp' => time(),
                        'iso_timestamp' => date('c')
                    ]
                );

                // Use the new data method to get pending migrations
                $data = $this->getPendingMigrationsData();

                // Enhanced success logging with comprehensive metrics
                $this->auditLogger->audit(
                    AuditEvent::CATEGORY_SYSTEM,
                    'pending_migrations_retrieved',
                    AuditEvent::SEVERITY_INFO,
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'controller' => static::class,
                        'action' => 'getPendingMigrations',
                        'resource' => 'migrations',
                        'operation' => 'read_pending_success',
                        'results' => [
                            'pending_count' => $data['pending_count'],
                            'migration_files' => array_map(function ($m) {
                                return $m['name'];
                            }, $data['migrations']),
                            'total_size' => array_sum(array_map(function ($m) {
                                return strlen($m['migration_file']);
                            }, $data['migrations']))
                        ],
                        'performance' => [
                            'memory_usage' => memory_get_usage(true),
                            'peak_memory' => memory_get_peak_usage(true),
                            'execution_time_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2)
                        ],
                        'cache_info' => [
                            'cache_key' => 'pending_migrations',
                            'cache_ttl' => 300,
                            'cache_hit' => false  // This is the generation call
                        ],
                        'timestamp' => time(),
                        'iso_timestamp' => date('c')
                    ]
                );

                return Response::ok($data, 'Pending migrations retrieved successfully')->send();
            },
            300  // 5 minute cache for pending migrations
        );
    }
}
