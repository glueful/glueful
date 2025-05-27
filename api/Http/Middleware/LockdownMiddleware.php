<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Exceptions\SecurityException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Lockdown Middleware
 *
 * Enforces emergency security lockdown restrictions by:
 * - Checking if the system is in lockdown mode
 * - Blocking access to disabled endpoints
 * - Allowing only essential endpoints during lockdown
 * - Enforcing IP blocks
 * - Redirecting to maintenance page when appropriate
 *
 * @package Glueful\Http\Middleware
 */
class LockdownMiddleware implements MiddlewareInterface
{
    /**
     * Process an incoming request
     *
     * @param Request $request The request
     * @param RequestHandlerInterface $handler The handler to process the request
     * @return SymfonyResponse The response
     * @throws SecurityException If access is blocked
     */
    public function process(Request $request, RequestHandlerInterface $handler): SymfonyResponse
    {
        // Check if system is in lockdown mode
        if (!$this->isSystemInLockdown()) {
            return $handler->handle($request);
        }

        $clientIp = $this->getClientIP($request);
        $requestPath = $this->getRequestPath($request);

        // Check if IP is blocked
        if ($this->isIPBlocked($clientIp)) {
            $this->logBlockedAccess($clientIp, $requestPath, 'blocked_ip');
            throw new SecurityException('Access denied due to security restrictions', 403);
        }

        // Check if endpoint is allowed during lockdown
        if (!$this->isEndpointAllowed($requestPath)) {
            $this->logBlockedAccess($clientIp, $requestPath, 'disabled_endpoint');

            // Return maintenance response for web requests
            if ($this->isWebRequest($request)) {
                return $this->getMaintenanceResponse();
            }

            // Return JSON error for API requests
            return $this->getLockdownApiResponse();
        }

        // Log allowed access during lockdown
        $this->logLockdownAccess($clientIp, $requestPath);

        return $handler->handle($request);
    }

    /**
     * Check if system is in lockdown mode
     *
     * @return bool
     */
    private function isSystemInLockdown(): bool
    {
        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        if (!file_exists($maintenanceFile)) {
            return false;
        }

        $maintenanceData = json_decode(file_get_contents($maintenanceFile), true);

        if (!$maintenanceData || !($maintenanceData['enabled'] ?? false)) {
            return false;
        }

        // Check if lockdown has expired
        if (isset($maintenanceData['end_time']) && time() > $maintenanceData['end_time']) {
            $this->disableLockdown();
            return false;
        }

        return $maintenanceData['lockdown_mode'] ?? false;
    }

    /**
     * Get client IP address
     *
     * @param Request $_request Request object (unused - uses $_SERVER directly)
     * @return string
     */
    private function getClientIP(Request $_request): string
    {
        // Try various headers for real IP
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_REAL_IP',            // Nginx
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (
                    filter_var(
                        $ip,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    )
                ) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get request path
     *
     * @param Request $request Request object
     * @return string
     */
    private function getRequestPath(Request $request): string
    {
        return $request->getPathInfo() ?: '/';
    }

    /**
     * Check if IP is blocked
     *
     * @param string $ip IP address
     * @return bool
     */
    private function isIPBlocked(string $ip): bool
    {
        $storagePath = config('app.paths.storage', './storage/');
        $blockedIpsFile = $storagePath . 'blocked_ips.json';

        if (!file_exists($blockedIpsFile)) {
            return false;
        }

        $blockedIps = json_decode(file_get_contents($blockedIpsFile), true) ?: [];

        if (!isset($blockedIps[$ip])) {
            return false;
        }

        $blockData = $blockedIps[$ip];

        // Check if block has expired
        if (isset($blockData['expires_at']) && time() > $blockData['expires_at']) {
            $this->unblockIP($ip);
            return false;
        }

        return true;
    }

    /**
     * Check if endpoint is allowed during lockdown
     *
     * @param string $path Request path
     * @return bool
     */
    private function isEndpointAllowed(string $path): bool
    {
        $storagePath = config('app.paths.storage', './storage/');
        $lockdownRoutes = $storagePath . 'lockdown_routes.json';

        if (!file_exists($lockdownRoutes)) {
            return true; // No restrictions if file doesn't exist
        }

        $routeData = json_decode(file_get_contents($lockdownRoutes), true);

        if (!$routeData) {
            return true;
        }

        $allowedEndpoints = $routeData['allowed_endpoints'] ?? [];
        $disabledEndpoints = $routeData['disabled_endpoints'] ?? [];

        // Check explicitly allowed endpoints first
        foreach ($allowedEndpoints as $allowedPath) {
            if ($this->pathMatches($path, $allowedPath)) {
                return true;
            }
        }

        // Check disabled endpoints
        foreach ($disabledEndpoints as $disabledPath) {
            if ($this->pathMatches($path, $disabledPath)) {
                return false;
            }
        }

        // If '*' is in disabled endpoints, block everything except allowed
        if (in_array('*', $disabledEndpoints)) {
            return false;
        }

        return true; // Allow by default if not explicitly disabled
    }

    /**
     * Check if path matches pattern (supports wildcards)
     *
     * @param string $path Actual request path
     * @param string $pattern Pattern to match against
     * @return bool
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if ($pattern === $path) {
            return true;
        }

        // Handle wildcard patterns like /api/admin/*
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -2);
            return str_starts_with($path, $prefix);
        }

        return false;
    }

    /**
     * Check if request is a web request (vs API)
     *
     * @param mixed $request Request object
     * @return bool
     */
    private function isWebRequest($request): bool
    {
        $path = $this->getRequestPath($request);

        // Consider API requests
        if (str_starts_with($path, '/api/')) {
            return false;
        }

        // Check Accept header for JSON
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($acceptHeader, 'application/json')) {
            return false;
        }

        return true;
    }

    /**
     * Get maintenance mode response for web requests
     *
     * @return SymfonyResponse
     */
    private function getMaintenanceResponse(): SymfonyResponse
    {
        $storagePath = config('app.paths.storage', './storage/');
        $maintenanceFile = $storagePath . 'framework/maintenance.json';

        $maintenanceData = [];
        if (file_exists($maintenanceFile)) {
            $maintenanceData = json_decode(file_get_contents($maintenanceFile), true) ?: [];
        }

        $message = $maintenanceData['message'] ?? 'System temporarily unavailable for maintenance';
        $endTime = $maintenanceData['end_time'] ?? null;

        $html = $this->generateMaintenanceHTML($message, $endTime);

        $response = new SymfonyResponse($html, 503, [
            'Content-Type' => 'text/html; charset=UTF-8'
        ]);

        if ($endTime) {
            $response->headers->set('Retry-After', (string)($endTime - time()));
        } else {
            $response->headers->set('Retry-After', '3600');
        }

        return $response;
    }

    /**
     * Get lockdown API response
     *
     * @return SymfonyResponse
     */
    private function getLockdownApiResponse(): SymfonyResponse
    {
        $response = [
            'error' => 'Service Unavailable',
            'message' => 'System is currently in security lockdown mode',
            'code' => 503,
            'type' => 'security_lockdown'
        ];

        return new SymfonyResponse(json_encode($response), 503, [
            'Content-Type' => 'application/json'
        ]);
    }

    /**
     * Generate maintenance mode HTML page
     *
     * @param string $message Maintenance message
     * @param int|null $endTime Expected end time
     * @return string
     */
    private function generateMaintenanceHTML(string $message, ?int $endTime): string
    {
        $endTimeText = '';
        if ($endTime) {
            $endTimeText = '<p>Expected to be resolved by: ' . date('Y-m-d H:i:s T', $endTime) . '</p>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container {
            max-width: 600px; margin: 100px auto; background: white; padding: 40px; 
            border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center;
        }
        h1 { color: #e74c3c; margin-bottom: 20px; }
        p { color: #666; line-height: 1.6; margin-bottom: 20px; }
        .status { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔒 System Maintenance</h1>
        <div class="status">
            <p><strong>{$message}</strong></p>
            {$endTimeText}
        </div>
        <p>We apologize for any inconvenience. Please try again later.</p>
        <p><small>If you believe you are seeing this message in error, please contact support.</small></p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Disable lockdown mode (cleanup expired lockdown)
     *
     * @return void
     */
    private function disableLockdown(): void
    {
        $storagePath = config('app.paths.storage', './storage/');

        // Remove maintenance file
        $maintenanceFile = $storagePath . 'framework/maintenance.json';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }

        // Remove lockdown routes
        $lockdownRoutes = $storagePath . 'lockdown_routes.json';
        if (file_exists($lockdownRoutes)) {
            unlink($lockdownRoutes);
        }

        // Remove enhanced logging
        $enhancedLogging = $storagePath . 'enhanced_logging.json';
        if (file_exists($enhancedLogging)) {
            unlink($enhancedLogging);
        }

        // Remove registration disabled flag
        $registrationFile = $storagePath . 'registration_disabled.json';
        if (file_exists($registrationFile)) {
            unlink($registrationFile);
        }
    }

    /**
     * Unblock an IP address (cleanup expired block)
     *
     * @param string $ip IP address to unblock
     * @return void
     */
    private function unblockIP(string $ip): void
    {
        $storagePath = config('app.paths.storage', './storage/');
        $blockedIpsFile = $storagePath . 'blocked_ips.json';

        if (!file_exists($blockedIpsFile)) {
            return;
        }

        $blockedIps = json_decode(file_get_contents($blockedIpsFile), true) ?: [];

        if (isset($blockedIps[$ip])) {
            unset($blockedIps[$ip]);
            file_put_contents($blockedIpsFile, json_encode($blockedIps, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Log blocked access attempt
     *
     * @param string $ip Client IP
     * @param string $path Request path
     * @param string $reason Block reason
     * @return void
     */
    private function logBlockedAccess(string $ip, string $path, string $reason): void
    {
        try {
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();

            $auditLogger->audit(
                'security',
                'lockdown_access_blocked',
                \Glueful\Logging\AuditEvent::SEVERITY_WARNING,
                [
                    'ip_address' => $ip,
                    'request_path' => $path,
                    'block_reason' => $reason,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'timestamp' => time()
                ]
            );
        } catch (\Exception $e) {
            error_log("Failed to log blocked access: {$e->getMessage()}");
        }
    }

    /**
     * Log allowed access during lockdown
     *
     * @param string $ip Client IP
     * @param string $path Request path
     * @return void
     */
    private function logLockdownAccess(string $ip, string $path): void
    {
        try {
            $auditLogger = \Glueful\Logging\AuditLogger::getInstance();

            $auditLogger->audit(
                'security',
                'lockdown_access_allowed',
                \Glueful\Logging\AuditEvent::SEVERITY_INFO,
                [
                    'ip_address' => $ip,
                    'request_path' => $path,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'timestamp' => time()
                ]
            );
        } catch (\Exception) {
            // Don't fail request if logging fails
        }
    }
}
