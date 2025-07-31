<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\AuthBootstrap;
use Glueful\Auth\AuthenticationManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Controllers\Traits\CachedUserContextTrait;
use Glueful\Controllers\Traits\AuthorizationTrait;
use Glueful\Controllers\Traits\RateLimitingTrait;
use Glueful\Controllers\Traits\ResponseCachingTrait;
use Glueful\Models\User;
use Glueful\Http\RequestUserContext;
use Glueful\Http\Response;
use Glueful\Exceptions\ValidationException;
use Glueful\Serialization\Serializer;
use Glueful\Serialization\Context\SerializationContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base Controller
 *
 * Provides core functionality for all controllers through focused traits.
 * Each trait handles a specific responsibility for better maintainability.
 *
 * Traits used:
 * - DatabaseConnectionTrait: Database connection management
 * - CachedUserContextTrait: Cached user context and permissions
 * - AuthorizationTrait: Authorization and permission checks
 * - RateLimitingTrait: Rate limiting functionality
 * - ResponseCachingTrait: Response and query caching
 *
 * @package Glueful\Controllers
 */
abstract class BaseController
{
    use DatabaseConnectionTrait;
    use CachedUserContextTrait;
    use AuthorizationTrait;
    use RateLimitingTrait;
    use ResponseCachingTrait;

    /**
     * @var AuthenticationManager Authentication manager instance
     */
    protected AuthenticationManager $authManager;

    /**
     * @var RepositoryFactory Repository factory for creating repository instances
     */
    protected RepositoryFactory $repositoryFactory;

    /**
     * @var Serializer Serializer instance for response serialization
     */
    protected Serializer $serializer;

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
     * @param Request|null $request HTTP request
     * @param Serializer|null $serializer Serializer instance
     */
    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?Request $request = null,
        ?Serializer $serializer = null
    ) {
        // Initialize authentication system
        $this->authManager = $authManager ?? AuthBootstrap::getManager();

        // Initialize repository factory
        $this->repositoryFactory = $repositoryFactory ?? new RepositoryFactory();

        // Initialize serializer
        $this->serializer = $serializer ?? container()->get(Serializer::class);

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
        array $groups = [],
        string $message = 'Data retrieved successfully'
    ): Response {
        return $this->serializeWithMeta($data, $meta, $groups, $message);
    }

    /**
     * Serialization Helper Methods
     */

    /**
     * Create serialized response with serialization groups
     *
     * Generates a JSON response using the framework's serialization system
     * with optional serialization groups for controlling field visibility.
     *
     * **Serialization Groups:**
     * - Control which fields are included in JSON output
     * - Support nested object serialization
     * - Enable different views for same data (public vs admin)
     *
     * **Usage Examples:**
     * ```php
     * // Basic serialization
     * return $this->serializeResponse($userData);
     *
     * // With groups for public API
     * return $this->serializeResponse($userData, ['public']);
     *
     * // With multiple groups
     * return $this->serializeResponse($userData, ['public', 'profile']);
     * ```
     *
     * @param mixed $data Data to serialize (array, object, or primitive)
     * @param array $groups Serialization groups to control field visibility
     * @param string $message Response message for client
     * @return Response JSON response with serialized data
     */
    protected function serializeResponse(mixed $data, array $groups = [], string $message = 'Success'): Response
    {
        $context = SerializationContext::create();

        if (!empty($groups)) {
            $context->withGroups($groups);
        }

        return Response::success($data, $message, $context);
    }


    /**
     * Create serialized created response
     */
    protected function serializeCreated(
        mixed $data,
        array $groups = [],
        string $message = 'Created successfully'
    ): Response {
        $context = SerializationContext::create();

        if (!empty($groups)) {
            $context->withGroups($groups);
        }

        return Response::created($data, $message, $context);
    }

    /**
     * Create response with custom serialization context
     */
    protected function serializeWithContext(
        mixed $data,
        SerializationContext $context,
        string $message = 'Success'
    ): Response {
        return Response::success($data, $message, $context);
    }

    /**
     * Create serialized response with metadata (flattened structure)
     */
    protected function serializeWithMeta(
        array $data,
        array $meta,
        array $groups = [],
        string $message = 'Data retrieved successfully'
    ): Response {
        $context = SerializationContext::create();

        if (!empty($groups)) {
            $context->withGroups($groups);
        }

        return Response::successWithMeta($data, $meta, $message, $context);
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
     * Validate request data with built-in validation rules
     *
     * Performs basic validation on request data and returns validation error
     * response if any rules fail. Provides common validation patterns for
     * quick field validation without external validator dependencies.
     *
     * **Supported Validation Rules:**
     * - `required`: Field must be present and non-empty
     * - `email`: Field must be valid email format
     * - `max:N`: Field length must not exceed N characters
     *
     * **Usage Examples:**
     * ```php
     * $rules = [
     *     'email' => 'required|email',
     *     'name' => 'required|max:100',
     *     'phone' => 'max:20'
     * ];
     *
     * $validationError = $this->validateRequest($requestData, $rules);
     * if ($validationError) {
     *     return $validationError; // Return 422 validation error response
     * }
     * ```
     *
     * @param array $data Request data to validate
     * @param array $rules Validation rules keyed by field name
     * @return Response|null Validation error response if validation fails, null if valid
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
     * Handle exceptions and return appropriate HTTP error response
     *
     * Centralizes exception handling across all controllers with proper
     * error logging and user-friendly error responses. Converts framework
     * exceptions to appropriate HTTP status codes.
     *
     * **Exception Handling:**
     * - ValidationException: Returns 422 with field-specific errors
     * - Other exceptions: Returns 500 with generic error message
     * - All exceptions logged for debugging and monitoring
     *
     * **Security Features:**
     * - Prevents sensitive error details from leaking to clients
     * - Comprehensive error logging for troubleshooting
     * - Consistent error response format across application
     *
     * @param \Exception $e Exception to handle and convert to HTTP response
     * @return Response Appropriate HTTP error response based on exception type
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
     * Extract request data with automatic content-type detection
     *
     * Intelligently parses request body based on Content-Type header,
     * supporting both JSON and form-encoded data formats. Provides
     * unified access to request data regardless of encoding.
     *
     * **Supported Content Types:**
     * - `application/json`: Parses JSON body to associative array
     * - `application/x-www-form-urlencoded`: Uses standard form data
     * - `multipart/form-data`: Uses standard form data
     *
     * **Usage Examples:**
     * ```php
     * // Works with JSON requests
     * $data = $this->getRequestData();
     * $email = $data['email'] ?? null;
     *
     * // Works with form requests
     * $data = $this->getRequestData();
     * $name = $data['name'] ?? null;
     * ```
     *
     * @return array Parsed request data as associative array
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
     * Extract PUT/PATCH request data with format detection
     *
     * Handles PUT and PATCH request data parsing for both JSON and
     * form-encoded content. Essential for RESTful API endpoints that
     * update resources using HTTP PUT/PATCH methods.
     *
     * **Content Type Handling:**
     * - JSON: Decodes JSON body to associative array
     * - Form-encoded: Parses URL-encoded body data
     * - Raw data: Handles custom content formats
     *
     * **HTTP Method Support:**
     * - PUT: Complete resource updates
     * - PATCH: Partial resource updates
     * - Works with browsers that don't natively support PUT/PATCH
     *
     * @return array Parsed request data for PUT/PATCH operations
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
