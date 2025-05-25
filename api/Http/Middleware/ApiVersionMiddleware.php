<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API Version Middleware
 *
 * PSR-15 compatible middleware that handles API versioning for requests.
 * Supports both URL-based and header-based versioning strategies.
 *
 * Features:
 * - Multiple versioning strategies (URL, header, both)
 * - Version validation against supported versions
 * - Automatic version prefix handling
 * - Configurable default version fallback
 * - Request version injection for downstream handlers
 * - Response version headers
 */
class ApiVersionMiddleware implements MiddlewareInterface
{
    /** @var array Supported API versions */
    private array $supportedVersions;

    /** @var string Default version to use when none specified */
    private string $defaultVersion;

    /** @var string Versioning strategy: 'url', 'header', or 'both' */
    private string $strategy;

    /** @var string Current API version */
    private string $currentVersion;

    /**
     * Create a new API version middleware
     *
     * @param array $supportedVersions List of supported API versions (e.g., ['v1', 'v2'])
     * @param string $defaultVersion Default version to use when none specified
     * @param string $strategy Versioning strategy ('url', 'header', 'both')
     * @param string $currentVersion Current API version
     */
    public function __construct(
        ?array $supportedVersions = null,
        ?string $defaultVersion = null,
        ?string $strategy = null,
        ?string $currentVersion = null
    ) {
        // Load configuration from app config if not provided
        $versioningConfig = config('app.versioning', []);

        $this->supportedVersions = $supportedVersions ?? $versioningConfig['supported'] ?? ['v1'];
        $this->defaultVersion = $defaultVersion ?? $versioningConfig['default'] ?? 'v1';
        $this->strategy = $strategy ?? $versioningConfig['strategy'] ?? 'url';
        $this->currentVersion = $currentVersion ?? $versioningConfig['current'] ?? 'v1';
    }

    /**
     * Process the request through the API version middleware
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $requestedVersion = $this->extractVersionFromRequest($request);

        // Use default version if none specified
        if (!$requestedVersion) {
            $requestedVersion = $this->defaultVersion;
        }

        // Validate the requested version
        if (!in_array($requestedVersion, $this->supportedVersions)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'API version not supported',
                'error' => [
                    'requested_version' => $requestedVersion,
                    'supported_versions' => $this->supportedVersions
                ],
                'code' => 400
            ], 400);
        }

        // Set the version in request attributes for downstream handlers
        $request->attributes->set('api_version', $requestedVersion);

        // Set version prefix in router if using URL strategy
        if (in_array($this->strategy, ['url', 'both'])) {
            \Glueful\Http\Router::setVersion($requestedVersion);
        }

        // Process the request
        $response = $handler->handle($request);

        // Add version information to response headers
        $response = $this->addVersionHeaders($response, $requestedVersion);

        return $response;
    }

    /**
     * Extract API version from request based on strategy
     *
     * @param Request $request The incoming request
     * @return string|null The extracted version or null if not found
     */
    private function extractVersionFromRequest(Request $request): ?string
    {
        $version = null;

        // Try header-based versioning
        if (in_array($this->strategy, ['header', 'both'])) {
            $version = $request->headers->get('X-API-Version')
                    ?? $request->headers->get('API-Version')
                    ?? $request->headers->get('Accept-Version');
        }

        // Try URL-based versioning
        if (!$version && in_array($this->strategy, ['url', 'both'])) {
            $pathInfo = $request->getPathInfo();

            // Extract version from URL pattern like /v1/users or /api/v2/users
            if (preg_match('#^/?(?:api/)?([vV]?\d+(?:\.\d+)?)(?:/|$)#', $pathInfo, $matches)) {
                $version = strtolower($matches[1]);
                // Ensure version has 'v' prefix
                if (!str_starts_with($version, 'v')) {
                    $version = 'v' . $version;
                }
            }
        }

        return $version;
    }

    /**
     * Add version-related headers to the response
     *
     * @param Response $response The response object
     * @param string $version The API version being used
     * @return Response The response with added headers
     */
    private function addVersionHeaders(Response $response, string $version): Response
    {
        // Add current API version to response
        $response->headers->set('X-API-Version', $version);

        // Add information about supported versions
        $response->headers->set('X-API-Supported-Versions', implode(', ', $this->supportedVersions));

        // Add current framework version
        $response->headers->set('X-API-Current-Version', $this->currentVersion);

        // Add deprecation warning if using an old version
        if ($version !== $this->currentVersion && in_array($version, $this->supportedVersions)) {
            $response->headers->set(
                'X-API-Deprecation-Warning',
                "API version {$version} is deprecated. Please upgrade to {$this->currentVersion}"
            );
        }

        return $response;
    }
}
