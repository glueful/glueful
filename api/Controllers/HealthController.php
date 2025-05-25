<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Services\HealthService;

class HealthController
{
    /**
     * Get overall system health status
     *
     * @return mixed HTTP response with health check results
     */
    public function index()
    {
        $health = HealthService::getOverallHealth();

        if ($health['status'] === 'error') {
            return Response::error(
                'System health check failed',
                Response::HTTP_SERVICE_UNAVAILABLE,
                null,
                null,
                $health
            )->send();
        }

        return Response::ok($health, 'System health check completed')->send();
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
