<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Helpers\{Utils, DatabaseConnectionTrait};
use Glueful\Auth\{AuthBootstrap, AuthenticationService, TokenStorageService};
use Glueful\Logging\{AuditLogger, AuditEvent};
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Exceptions\{SecurityException, NotFoundException};
use Glueful\Database\QueryBuilder;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * BaseController
 *
 * Abstract base class that provides common functionality for all controllers.
 * Handles authentication, permissions, request parsing, response formatting,
 * and error handling patterns used across the application.
 *
 * @package Glueful\Controllers
 */
abstract class BaseController
{
    use DatabaseConnectionTrait;

    protected AuthenticationService $authService;
    protected TokenStorageService $tokenStorage;
    protected AuditLogger $auditLogger;
    protected QueryBuilder $queryBuilder;
    protected $authManager;

    /**
     * Initialize common dependencies
     */
    public function __construct()
    {
        // Initialize authentication system
        AuthBootstrap::initialize();
        $this->authManager = AuthBootstrap::getManager();
        $this->authService = new AuthenticationService();
        $this->tokenStorage = new TokenStorageService();

        // Initialize audit logger
        $this->auditLogger = AuditLogger::getInstance();

        // Initialize query builder
        $this->queryBuilder = $this->getQueryBuilder();
    }

    /**
     * Authenticate a request using multiple authentication methods
     *
     * @param SymfonyRequest $request The HTTP request to authenticate
     * @param array $providers Authentication providers to try (default: ['jwt', 'api_key'])
     * @return array|null User data if authenticated, null otherwise
     */
    protected function authenticate(SymfonyRequest $request, array $providers = ['jwt', 'api_key']): ?array
    {
        try {
            return $this->authManager->authenticateWithProviders($providers, $request);
        } catch (\Exception $e) {
            $this->logAuthenticationError($e, $request);
            return null;
        }
    }

    /**
     * Validate token and return user data
     *
     * @param SymfonyRequest|null $request
     * @return array User data
     * @throws SecurityException If token is invalid
     */
    protected function validateToken(?SymfonyRequest $request = null): array
    {
        $request = $request ?? SymfonyRequest::createFromGlobals();

        $token = $this->extractTokenFromRequest($request);
        if (!$token) {
            throw new SecurityException('No token provided');
        }

        $authManager = AuthBootstrap::getManager();
        $userData = $authManager->authenticate($request);

        if (!$userData) {
            throw new SecurityException('Invalid or expired token');
        }

        return $userData;
    }

    /**
     * Extract user UUID from authentication data
     *
     * Handles different authentication response formats
     *
     * @param array $authData Authentication data
     * @return string|null User UUID
     */
    protected function getUserUuid(array $authData): ?string
    {
        // For JWT auth, check user_uuid field first (auth_sessions table)
        if (isset($authData['user_uuid'])) {
            return $authData['user_uuid'];
        }

        // Direct UUID in auth data
        if (isset($authData['uuid'])) {
            return $authData['uuid'];
        }

        // UUID nested in user object
        if (isset($authData['user']['uuid'])) {
            return $authData['user']['uuid'];
        }

        // UUID in nested user data (some providers return this structure)
        if (isset($authData['data']['user']['uuid'])) {
            return $authData['data']['user']['uuid'];
        }

        return null;
    }

    /**
     * Extract token from request headers
     *
     * @param SymfonyRequest $request
     * @return string|null
     */
    protected function extractTokenFromRequest(SymfonyRequest $request): ?string
    {
        return AuthenticationService::extractTokenFromRequest($request);
    }

    /**
     * Check if current user has permission for an operation
     *
     * @param SymfonyRequest $request HTTP request object
     * @param string $permission Permission to check
     * @param string $category Permission category (default: 'system')
     * @param array $context Additional context
     * @return void
     * @throws SecurityException If permission check fails
     */
    protected function checkPermission(
        SymfonyRequest $request,
        string $permission,
        string $category = 'system',
        array $context = []
    ): void {
        $userUuid = $request->attributes->get('user_uuid');

        if (!$userUuid) {
            throw new SecurityException('User authentication required for this operation');
        }

        // Check if permission system is available
        if (!PermissionHelper::isAvailable()) {
            // Fallback: Allow any authenticated user when permission system unavailable
            error_log("FALLBACK: Permission system unavailable, allowing authenticated access for: {$permission}");
            return;
        }

        if (!PermissionHelper::hasPermission($userUuid, $permission, $category, $context)) {
            throw new SecurityException("Insufficient permissions: {$permission} required");
        }
    }

    /**
     * Get current authenticated user data
     *
     * @return array|null User data or null if not authenticated
     */
    protected function getCurrentUser(): ?array
    {
        return Utils::getCurrentUser();
    }

    /**
     * Parse pagination parameters from request
     *
     * @param array $queryParams Query parameters
     * @param int $defaultPerPage Default items per page
     * @param int $maxPerPage Maximum items per page
     * @return array Pagination parameters
     */
    protected function parsePaginationParams(
        array $queryParams,
        int $defaultPerPage = 25,
        int $maxPerPage = 100
    ): array {
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $perPage = min($maxPerPage, max(1, (int)($queryParams['per_page'] ?? $defaultPerPage)));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'limit' => $perPage
        ];
    }

    /**
     * Parse sorting parameters from request
     *
     * @param array $queryParams Query parameters
     * @param string $defaultSort Default sort field
     * @param string $defaultOrder Default sort order
     * @param array $allowedFields Allowed sort fields
     * @return array Sort parameters
     */
    protected function parseSortParams(
        array $queryParams,
        string $defaultSort = 'created_at',
        string $defaultOrder = 'desc',
        array $allowedFields = []
    ): array {
        $sort = $queryParams['sort'] ?? $defaultSort;
        $order = strtolower($queryParams['order'] ?? $defaultOrder);

        // Validate sort field if allowed fields are specified
        if (!empty($allowedFields) && !in_array($sort, $allowedFields)) {
            $sort = $defaultSort;
        }

        // Validate sort order
        $order = in_array($order, ['asc', 'desc']) ? $order : $defaultOrder;

        return [
            'sort' => $sort,
            'order' => $order,
            'order_by' => [$sort => $order]
        ];
    }

    /**
     * Parse filtering conditions from query parameters
     *
     * @param array $queryParams Query parameters
     * @param array $excludeParams Parameters to exclude from filtering
     * @return array Filter conditions
     */
    protected function parseFilterConditions(
        array $queryParams,
        array $excludeParams = ['page', 'per_page', 'sort', 'order', 'fields']
    ): array {
        $conditions = [];

        foreach ($queryParams as $key => $value) {
            if (in_array($key, $excludeParams) || empty($value)) {
                continue;
            }

            // Simple equality conditions - override in child controllers for complex filtering
            $conditions[$key] = $value;
        }

        return $conditions;
    }

    /**
     * Parse fields to select from request parameters
     *
     * @param string $fields Comma-separated field list
     * @return array Array of fields to select
     */
    protected function parseSelectFields(string $fields): array
    {
        if (empty($fields) || $fields === '*') {
            return [];
        }

        return array_map('trim', explode(',', $fields));
    }

    /**
     * Create standardized success response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @return Response
     */
    protected function successResponse(
        $data = null,
        string $message = 'Success'
    ): Response {
        return Response::ok($data, $message);
    }

    /**
     * Create standardized error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string|null $errorType Error type
     * @param string|null $errorCode Error code
     * @param array|null $details Additional error details
     * @return Response
     */
    protected function errorResponse(
        string $message,
        int $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR,
        ?string $errorType = null,
        ?string $errorCode = null,
        ?array $details = null
    ): Response {
        return Response::error($message, $statusCode, $errorType, $errorCode, $details);
    }

    /**
     * Create not found response
     *
     * @param string $message Not found message
     * @return Response
     */
    protected function notFoundResponse(string $message = 'Resource not found'): Response
    {
        return Response::notFound($message);
    }

    /**
     * Create unauthorized response
     *
     * @param string $message Unauthorized message
     * @return Response
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): Response
    {
        return Response::unauthorized($message);
    }

    /**
     * Create validation error response
     *
     * @param string $message Validation error message
     * @param array|null $errors Validation errors
     * @return Response
     */
    protected function validationErrorResponse(
        string $message = 'Validation failed',
        ?array $errors = null
    ): Response {
        return Response::error(
            $message,
            Response::HTTP_BAD_REQUEST,
            Response::ERROR_VALIDATION,
            'VALIDATION_FAILED',
            $errors
        );
    }

    /**
     * Log authentication events to audit logger
     *
     * @param string $event Event type
     * @param string|null $userId User ID
     * @param array $context Event context
     * @param string $severity Event severity
     * @return void
     */
    protected function logAuthEvent(
        string $event,
        ?string $userId = null,
        array $context = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): void {
        $this->auditLogger->authEvent($event, $userId, $context, $severity);
    }

    /**
     * Log general audit events
     *
     * @param string $category Event category
     * @param string $event Event type
     * @param string $severity Event severity
     * @param array $context Event context
     * @return void
     */
    protected function logAuditEvent(
        string $category,
        string $event,
        string $severity = AuditEvent::SEVERITY_INFO,
        array $context = []
    ): void {
        $this->auditLogger->audit($category, $event, $severity, $context);
    }

    /**
     * Log authentication errors
     *
     * @param \Exception $exception
     * @param SymfonyRequest $request
     * @return void
     */
    protected function logAuthenticationError(\Exception $exception, SymfonyRequest $request): void
    {
        $this->auditLogger->authEvent(
            'authentication_error',
            null,
            [
                'error_message' => $exception->getMessage(),
                'ip_address' => $request->getClientIp() ?? 'unknown',
                'user_agent' => $request->headers->get('User-Agent') ?? 'unknown'
            ],
            AuditEvent::SEVERITY_ERROR
        );
    }

    /**
     * Handle common exceptions and return appropriate responses
     *
     * @param \Exception $exception
     * @param string $operation Operation that failed
     * @return Response
     */
    protected function handleException(\Exception $exception, string $operation = 'operation'): Response
    {
        if ($exception instanceof SecurityException) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        if ($exception instanceof NotFoundException) {
            return $this->notFoundResponse($exception->getMessage());
        }

        // Log the error
        error_log("Controller {$operation} error: " . $exception->getMessage());

        return $this->errorResponse(
            "Failed to {$operation}: " . $exception->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * Get client IP address from request
     *
     * @param SymfonyRequest|null $request
     * @return string
     */
    protected function getClientIp(?SymfonyRequest $request = null): string
    {
        $request = $request ?? SymfonyRequest::createFromGlobals();
        return $request->getClientIp() ?? 'unknown';
    }

    /**
     * Get user agent from request
     *
     * @param SymfonyRequest|null $request
     * @return string
     */
    protected function getUserAgent(?SymfonyRequest $request = null): string
    {
        $request = $request ?? SymfonyRequest::createFromGlobals();
        return $request->headers->get('User-Agent') ?? 'unknown';
    }

    /**
     * Build pagination response metadata
     *
     * @param int $currentPage Current page number
     * @param int $perPage Items per page
     * @param int $total Total items
     * @return array Pagination metadata
     */
    protected function buildPaginationMeta(int $currentPage, int $perPage, int $total): array
    {
        return [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage),
            'has_more' => $currentPage < ceil($total / $perPage)
        ];
    }

    /**
     * Require authentication for the request
     *
     * @param SymfonyRequest|null $request
     * @return array User data
     * @throws SecurityException If authentication fails
     */
    protected function requireAuthentication(?SymfonyRequest $request = null): array
    {
        $request = $request ?? SymfonyRequest::createFromGlobals();
        $userData = $this->authenticate($request);

        if (!$userData) {
            throw new SecurityException('Authentication required');
        }

        return $userData;
    }

    /**
     * Validate required fields in data array
     *
     * @param array $data Data to validate
     * @param array $requiredFields Required field names
     * @return void
     * @throws \InvalidArgumentException If required fields are missing
     */
    protected function validateRequired(array $data, array $requiredFields): void
    {
        $missingFields = ControllerHelpers::validateRequiredFields($data, $requiredFields);

        if (!empty($missingFields)) {
            throw new \InvalidArgumentException(
                'Missing required fields: ' . implode(', ', $missingFields)
            );
        }
    }

    /**
     * Get request data from various sources
     *
     * @param SymfonyRequest|null $request Symfony request instance
     * @return array Request data (POST, GET, or JSON)
     */
    protected function getRequestData(?SymfonyRequest $request = null): array
    {
        $request = $request ?? SymfonyRequest::createFromGlobals();

        // Try POST data first
        $postData = $request->request->all();
        if (!empty($postData)) {
            return $postData;
        }

        // Try JSON content
        $content = $request->getContent();
        if (!empty($content)) {
            $jsonData = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                return $jsonData;
            }
        }

        // Fall back to query parameters
        return $request->query->all();
    }

    /**
     * Get query parameters from request
     *
     * @param SymfonyRequest|null $request Symfony request instance
     * @return array Query parameters
     */
    protected function getQueryParams(?SymfonyRequest $request = null): array
    {
        $request = $request ?? SymfonyRequest::createFromGlobals();
        return $request->query->all();
    }
}
