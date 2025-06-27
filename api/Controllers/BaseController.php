<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\AuthBootstrap;
use Glueful\Auth\AuthenticationManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Controllers\Traits\AsyncAuditTrait;
use Glueful\Controllers\Traits\CachedUserContextTrait;
use Glueful\Controllers\Traits\AuthorizationTrait;
use Glueful\Controllers\Traits\RateLimitingTrait;
use Glueful\Controllers\Traits\ResponseCachingTrait;
use Glueful\Controllers\Traits\ResourceAuditingTrait;
use Glueful\Logging\AuditLogger;
use Glueful\Models\User;
use Glueful\Http\RequestUserContext;
use Glueful\Http\Response;
use Glueful\Exceptions\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base Controller
 *
 * Provides core functionality for all controllers through focused traits.
 * Each trait handles a specific responsibility for better maintainability.
 *
 * Traits used:
 * - DatabaseConnectionTrait: Database connection management
 * - AsyncAuditTrait: Asynchronous audit logging
 * - CachedUserContextTrait: Cached user context and permissions
 * - AuthorizationTrait: Authorization and permission checks
 * - RateLimitingTrait: Rate limiting functionality
 * - ResponseCachingTrait: Response and query caching
 * - ResourceAuditingTrait: Resource-specific audit logging
 *
 * @package Glueful\Controllers
 */
abstract class BaseController
{
    use DatabaseConnectionTrait;
    use AsyncAuditTrait;
    use CachedUserContextTrait;
    use AuthorizationTrait;
    use RateLimitingTrait;
    use ResponseCachingTrait;
    use ResourceAuditingTrait;

    /**
     * @var AuthenticationManager Authentication manager instance
     */
    protected AuthenticationManager $authManager;

    /**
     * @var RepositoryFactory Repository factory for creating repository instances
     */
    protected RepositoryFactory $repositoryFactory;

    /**
     * @var AuditLogger Audit logger instance
     */
    protected AuditLogger $auditLogger;

    /**
     * @var User|null Current authenticated user
     */
    protected ?User $currentUser = null;

    /**
     * @var string|null Current authentication token
     */
    protected ?string $currentToken = null;

    /**
     * @var Request Current HTTP request
     */
    protected Request $request;

    /**
     * @var RequestUserContext User context for caching
     */
    protected RequestUserContext $userContext;

    /**
     * BaseController constructor
     *
     * @param RepositoryFactory|null $repositoryFactory Repository factory instance
     * @param AuthenticationManager|null $authManager Authentication manager
     * @param AuditLogger|null $auditLogger Audit logger
     * @param Request|null $request HTTP request
     */
    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?Request $request = null
    ) {
        // Initialize authentication system
        $this->authManager = $authManager ?? AuthBootstrap::getManager();

        // Initialize repository factory
        $this->repositoryFactory = $repositoryFactory ?? new RepositoryFactory();

        // Initialize audit logger
        $this->auditLogger = $auditLogger ?? AuditLogger::getInstance();

        // Set request - use provided request or get from container
        $this->request = $request ?? container()->get(Request::class);

        // Initialize request user context for cached authentication
        $this->userContext = RequestUserContext::getInstance()->initialize();

        // Set current user and token from context (cached)
        $this->currentUser = $this->userContext->getUser();
        $this->currentToken = $this->userContext->getToken();
    }

    /**
     * Helper methods for common response patterns
     */

    /**
     * Create successful response with data
     */
    protected function success(mixed $data = null, string $message = 'Success'): Response
    {
        return Response::success($data, $message);
    }

    /**
     * Create created response (HTTP 201)
     */
    protected function created(mixed $data = null, string $message = 'Created successfully'): Response
    {
        return Response::created($data, $message);
    }

    /**
     * Create validation error response
     */
    protected function validationError(array $errors, string $message = 'Validation failed'): Response
    {
        return Response::validation($errors, $message);
    }

    /**
     * Create not found response
     */
    protected function notFound(string $message = 'Resource not found'): Response
    {
        return Response::notFound($message);
    }

    /**
     * Create unauthorized response
     */
    protected function unauthorized(string $message = 'Unauthorized'): Response
    {
        return Response::unauthorized($message);
    }

    /**
     * Create forbidden response
     */
    protected function forbidden(string $message = 'Forbidden'): Response
    {
        return Response::forbidden($message);
    }

    /**
     * Create server error response
     */
    protected function serverError(string $message = 'Internal server error'): Response
    {
        return Response::serverError($message);
    }

    /**
     * Create paginated response
     */
    protected function paginated(
        array $data,
        array $meta,
        string $message = 'Data retrieved successfully'
    ): Response {
        return Response::successWithMeta($data, $meta, $message);
    }

    /**
     * HTTP Caching Helper Methods
     */

    /**
     * Create cacheable response with HTTP caching headers
     */
    protected function cached(Response $response, int $maxAge = 300, bool $public = false): Response
    {
        $response->setMaxAge($maxAge);

        if ($public) {
            $response->setPublic();
        } else {
            $response->setPrivate();
        }

        // Add ETag for conditional requests
        $response->setEtag(md5($response->getContent()));

        return $response;
    }

    /**
     * Create public cacheable response (CDN-friendly)
     */
    protected function publicCached(Response $response, int $maxAge = 3600): Response
    {
        return $this->cached($response, $maxAge, true);
    }

    /**
     * Create private cacheable response (user-specific)
     */
    protected function privateCached(Response $response, int $maxAge = 300): Response
    {
        return $this->cached($response, $maxAge, false);
    }

    /**
     * Create cacheable success response
     */
    protected function cachedSuccess(
        mixed $data,
        string $message = 'Success',
        int $maxAge = 300,
        bool $public = false
    ): Response {
        $response = Response::success($data, $message);
        return $this->cached($response, $maxAge, $public);
    }

    /**
     * Create public cacheable success response (for static/configuration data)
     */
    protected function publicSuccess(mixed $data, string $message = 'Success', int $maxAge = 3600): Response
    {
        return $this->cachedSuccess($data, $message, $maxAge, true);
    }

    /**
     * Create response with Last-Modified header
     */
    protected function withLastModified(Response $response, \DateTime $lastModified): Response
    {
        $response->setLastModified($lastModified);
        return $response;
    }

    /**
     * Check if request is not modified based on ETag or Last-Modified
     */
    protected function checkNotModified(?\DateTime $lastModified = null, ?string $etag = null): ?Response
    {
        $ifModifiedSince = $this->request->headers->get('If-Modified-Since');
        $ifNoneMatch = $this->request->headers->get('If-None-Match');

        $notModified = false;

        // Check If-Modified-Since
        if ($ifModifiedSince && $lastModified) {
            $ifModifiedSinceDate = \DateTime::createFromFormat('D, d M Y H:i:s T', $ifModifiedSince);
            if ($ifModifiedSinceDate && $lastModified <= $ifModifiedSinceDate) {
                $notModified = true;
            }
        }

        // Check If-None-Match (ETag)
        if ($ifNoneMatch && $etag) {
            if ($ifNoneMatch === $etag || $ifNoneMatch === '"' . $etag . '"') {
                $notModified = true;
            }
        }

        if ($notModified) {
            $response = new Response('', 304);
            if ($etag) {
                $response->setEtag($etag);
            }
            if ($lastModified) {
                $response->setLastModified($lastModified);
            }
            return $response;
        }

        return null;
    }

    /**
     * Validate request data and return validation errors response if invalid
     */
    protected function validateRequest(array $data, array $rules): ?Response
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $isRequired = str_contains($rule, 'required');
            $value = $data[$field] ?? null;

            if ($isRequired && ($value === null || $value === '')) {
                $errors[$field] = "{$field} is required";
                continue;
            }

            if ($value !== null && str_contains($rule, 'email') && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "{$field} must be a valid email";
            }

            if ($value !== null && preg_match('/max:(\d+)/', $rule, $matches)) {
                $maxLength = (int) $matches[1];
                if (strlen((string) $value) > $maxLength) {
                    $errors[$field] = "{$field} must not exceed {$maxLength} characters";
                }
            }
        }

        return empty($errors) ? null : $this->validationError($errors);
    }

    /**
     * Handle exceptions and return appropriate error response
     */
    protected function handleException(\Exception $e): Response
    {
        if ($e instanceof ValidationException) {
            return $this->validationError($e->getErrors(), $e->getMessage());
        }

        // Log the exception for debugging
        error_log("Controller exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

        return $this->serverError('An unexpected error occurred');
    }

    /**
     * Get request data (POST/PUT/PATCH) with automatic JSON parsing
     *
     * Replacement for custom Request::getPostData() method
     */
    protected function getRequestData(): array
    {
        $contentType = $this->request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $content = $this->request->getContent();
            return $content ? json_decode($content, true) ?? [] : [];
        }

        return $this->request->request->all();
    }

    /**
     * Get PUT/PATCH data with automatic JSON parsing
     *
     * Replacement for custom Request::getPutData() method
     */
    protected function getPutData(): array
    {
        $contentType = $this->request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/json')) {
            $content = $this->request->getContent();
            return $content ? json_decode($content, true) ?? [] : [];
        }

        // For form-encoded PUT data
        $content = $this->request->getContent();
        parse_str($content, $data);
        return $data;
    }
}
