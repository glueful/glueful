<?php

declare(strict_types=1);

namespace Glueful;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Glueful\Helpers\StaticFileDetector;
use Glueful\Extensions\BaseExtension;

/**
 * SPA Manager
 *
 * Manages Single Page Application routing and serving for extensions.
 * Integrates with the Glueful Extensions system to provide SPA support.
 */
class SpaManager
{
    protected array $spaApps = [];
    protected LoggerInterface $logger;
    protected StaticFileDetector $staticFileDetector;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?StaticFileDetector $staticFileDetector = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->staticFileDetector = $staticFileDetector ?? new StaticFileDetector();
    }

    /**
     * Register SPA configurations from an extension
     *
     * @param string $extensionClass Extension class name
     * @return void
     */
    public function registerFromExtension(string $extensionClass): void
    {
        if (!class_exists($extensionClass)) {
            $this->logger->warning("Class {$extensionClass} does not exist");
            return;
        }

        if (!is_subclass_of($extensionClass, BaseExtension::class)) {
            $this->logger->warning("Class {$extensionClass} is not a valid extension");
            return;
        }

        try {
            $spaConfigs = $extensionClass::getSpaConfigurations();

            foreach ($spaConfigs as $config) {
                $this->registerSpaApp(
                    $config['path_prefix'],
                    $config['build_path'],
                    $config
                );
            }

            $this->logger->debug("Registered SPA configurations from {$extensionClass}", [
                'count' => count($spaConfigs)
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to register SPA from {$extensionClass}: " . $e->getMessage());
        }
    }

    /**
     * Register a single SPA application
     *
     * @param string $pathPrefix URL path prefix
     * @param string $buildPath Path to built SPA index.html
     * @param array $options Additional options
     * @return void
     */
    public function registerSpaApp(string $pathPrefix, string $buildPath, array $options = []): void
    {
        if (!file_exists($buildPath)) {
            $this->logger->warning("SPA build not found at {$buildPath}");
            return;
        }

        $this->spaApps[] = [
            'path_prefix' => rtrim($pathPrefix, '/'),
            'build_path' => $buildPath,
            'options' => $options,
            'registered_at' => time()
        ];

        // Sort by path length (longest first) for proper matching
        usort($this->spaApps, fn($a, $b) => strlen($b['path_prefix']) - strlen($a['path_prefix']));

        $this->logger->debug("Registered SPA app", [
            'path_prefix' => $pathPrefix,
            'build_path' => $buildPath,
            'name' => $options['name'] ?? 'Unknown'
        ]);
    }

    /**
     * Handle SPA routing for a request path
     *
     * @param string $requestPath Request path to match
     * @return bool Whether a SPA was served
     */
    public function handleSpaRouting(string $requestPath): bool
    {
        // First check if this is an asset request for any SPA
        if ($this->handleAssetRequest($requestPath)) {
            return true;
        }

        // Then check for SPA route matches
        foreach ($this->spaApps as $app) {
            if ($this->matchesPath($requestPath, $app['path_prefix'])) {
                if ($this->checkAccess($app['options'])) {
                    $this->serveSpaApp($app);
                    return true;
                } else {
                    $this->logger->warning("Access denied for SPA", [
                        'path' => $requestPath,
                        'spa' => $app['options']['name'] ?? 'Unknown'
                    ]);
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Handle asset requests for SPA applications
     *
     * @param string $requestPath Request path
     * @return bool Whether an asset was served
     */
    protected function handleAssetRequest(string $requestPath): bool
    {
        // Check if this looks like an asset request
        if (!preg_match('/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|map|json)$/', $requestPath)) {
            return false;
        }

        // For each registered SPA, check if the asset exists in its directory
        foreach ($this->spaApps as $app) {
            $publicPath = dirname($app['build_path']);
            $assetsPath = $app['options']['assets_path'] ?? dirname($app['build_path']) . '/assets';

            // Get the SPA's path prefix (e.g., "/ui/api/admin")
            $spaPrefix = $app['path_prefix'];

            // Handle requests that include the SPA prefix in the path
            if (str_starts_with($requestPath, $spaPrefix)) {
                $relativeAssetPath = substr($requestPath, strlen($spaPrefix));

                // Try public root files first (env.json, favicon, etc.)
                $publicFile = $publicPath . $relativeAssetPath;
                if (file_exists($publicFile)) {
                    $this->serveAsset($publicFile, $requestPath);
                    return true;
                }

                // Try assets directory
                if (str_starts_with($relativeAssetPath, '/assets/')) {
                    $assetFileName = substr($relativeAssetPath, 8); // Remove '/assets/'
                    $assetFile = $assetsPath . '/' . $assetFileName;
                    if (file_exists($assetFile)) {
                        $this->serveAsset($assetFile, $requestPath);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if request path matches SPA prefix
     *
     * @param string $requestPath Request path
     * @param string $prefix SPA path prefix
     * @return bool Whether path matches
     */
    protected function matchesPath(string $requestPath, string $prefix): bool
    {
        if ($prefix === '/') {
            return true; // Fallback matches everything
        }
        return str_starts_with($requestPath, $prefix);
    }

    /**
     * Check access permissions for SPA
     *
     * @param array $options SPA options
     * @return bool Whether access is allowed
     */
    protected function checkAccess(array $options): bool
    {
        // Basic authentication check
        if (!empty($options['auth_required'])) {
            // TODO: Implement your authentication logic
            // Example: return $this->isUserAuthenticated();
        }

        // Permission check
        if (!empty($options['permissions'])) {
            // TODO: Implement your permission system
            // Example: return $this->hasPermissions($options['permissions']);
        }

        return true; // Allow access by default
    }

    /**
     * Serve SPA application
     *
     * @param array $app SPA application configuration
     * @return void
     */
    protected function serveSpaApp(array $app): void
    {
        // Set appropriate headers
        header('Content-Type: text/html; charset=utf-8');

        // Optional: Add security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');

        // Serve the SPA
        readfile($app['build_path']);

        $this->logger->debug("Served SPA application", [
            'name' => $app['options']['name'] ?? 'Unknown',
            'path_prefix' => $app['path_prefix'],
            'build_path' => $app['build_path']
        ]);
    }

    /**
     * Serve a static asset file
     *
     * @param string $filePath Full path to the asset file
     * @param string $requestPath Original request path
     */
    protected function serveAsset(string $filePath, string $requestPath): void
    {
        // Determine content type
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json'
        ];

        $contentType = $contentTypes[$extension] ?? 'application/octet-stream';

        // Set headers
        header("Content-Type: {$contentType}");
        header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
        header('Content-Length: ' . filesize($filePath));

        // Serve the file
        readfile($filePath);

        $this->logger->debug("Served SPA asset", [
            'file_path' => $filePath,
            'request_path' => $requestPath,
            'content_type' => $contentType
        ]);
    }

    /**
     * Get all registered SPA applications
     *
     * @return array Registered SPA apps
     */
    public function getRegisteredApps(): array
    {
        return $this->spaApps;
    }

    /**
     * Get SPA statistics
     *
     * @return array SPA statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_apps' => count($this->spaApps),
            'frameworks' => [],
            'auth_required' => 0,
            'paths' => []
        ];

        foreach ($this->spaApps as $app) {
            // Count frameworks
            $framework = $app['options']['framework'] ?? 'unknown';
            $stats['frameworks'][$framework] = ($stats['frameworks'][$framework] ?? 0) + 1;

            // Count auth required
            if (!empty($app['options']['auth_required'])) {
                $stats['auth_required']++;
            }

            // Collect paths
            $stats['paths'][] = $app['path_prefix'];
        }

        return $stats;
    }

    /**
     * Clear all registered SPAs
     *
     * @return void
     */
    public function clear(): void
    {
        $this->spaApps = [];
        $this->logger->debug("Cleared all registered SPA applications");
    }
}
