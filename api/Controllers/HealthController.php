<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Services\HealthService;
use Glueful\Repository\RepositoryFactory;
use Glueful\Auth\AuthenticationManager;
use Glueful\Logging\AuditLogger;
use Symfony\Component\HttpFoundation\Request;

class HealthController extends BaseController
{
    /**
     * Constructor
     *
     * @param RepositoryFactory|null $repositoryFactory
     * @param AuthenticationManager|null $authManager
     * @param AuditLogger|null $auditLogger
     * @param Request|null $request
     */
    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?Request $request = null
    ) {
        parent::__construct($repositoryFactory, $authManager, $auditLogger, $request);
    }
    /**
     * Get overall system health status
     *
     * Public endpoint with rate limiting and caching for DDoS protection
     *
     * @return mixed HTTP response with health check results
     */
    public function index()
    {
        // Apply conditional rate limiting based on authentication
        // Anonymous users: 30 requests/minute per IP
        // Authenticated users: 100 requests/minute per user
        $this->conditionalRateLimit('health_check');

        // Cache response with short TTL for monitoring tools
        $response = $this->cacheResponse('health_overall', function () {
            // Optional audit logging for security monitoring
            if ($this->getCurrentUser()) {
                $this->auditLogger->audit(
                    'health',
                    'health_check_authenticated',
                    'info',
                    [
                        'user_uuid' => $this->getCurrentUserUuid(),
                        'endpoint' => 'overall_health',
                        'ip' => $this->request->getClientIp()
                    ]
                );
            }

            $health = HealthService::getOverallHealth();

            if ($health['status'] === 'error') {
                return [
                    'error' => true,
                    'status' => Response::HTTP_SERVICE_UNAVAILABLE,
                    'message' => 'System health check failed',
                    'data' => $health
                ];
            }

            return [
                'error' => false,
                'status' => Response::HTTP_OK,
                'message' => 'System health check completed',
                'data' => $health
            ];
        }, 30); // 30-second cache for fresh monitoring data

        // Convert cached response to actual Response object
        if ($response['error']) {
            return Response::error(
                $response['message'],
                $response['status'],
                null,
                null,
                $response['data']
            )->send();
        }

        return Response::ok($response['data'], $response['message'])->send();
    }

    /**
     * Get database health status only
     *
     * @return mixed HTTP response with database health
     */
    public function database()
    {
        $health = HealthService::checkDatabase();

        if ($health['status'] === 'error') {
            return Response::error(
                'Database health check failed',
                Response::HTTP_SERVICE_UNAVAILABLE,
                null,
                null,
                $health
            )->send();
        }

        return Response::ok($health, 'Database health check completed')->send();
    }

    /**
     * Get cache health status only
     *
     * @return mixed HTTP response with cache health
     */
    public function cache()
    {
        $health = HealthService::checkCache();

        if ($health['status'] === 'error') {
            return Response::error(
                'Cache health check failed',
                Response::HTTP_SERVICE_UNAVAILABLE,
                null,
                null,
                $health
            )->send();
        }

        return Response::ok($health, 'Cache health check completed')->send();
    }
}
