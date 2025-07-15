<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Constants\ErrorCodes;
use Glueful\Serialization\Serializer;
use Glueful\Serialization\Context\SerializationContext;

/**
 * Modern API Response Handler using Symfony JsonResponse
 *
 * Provides clean, standardized HTTP response formatting for the API.
 * Built on Symfony's JsonResponse for full middleware compatibility.
 */
class Response extends JsonResponse
{
    private static ?Serializer $serializer = null;

    // HTTP status codes from ErrorCodes
    public const HTTP_OK = ErrorCodes::SUCCESS;
    public const HTTP_CREATED = ErrorCodes::CREATED;
    public const HTTP_NO_CONTENT = ErrorCodes::NO_CONTENT;
    public const HTTP_BAD_REQUEST = ErrorCodes::BAD_REQUEST;
    public const HTTP_UNAUTHORIZED = ErrorCodes::UNAUTHORIZED;
    public const HTTP_FORBIDDEN = ErrorCodes::FORBIDDEN;
    public const HTTP_NOT_FOUND = ErrorCodes::NOT_FOUND;
    public const HTTP_METHOD_NOT_ALLOWED = ErrorCodes::METHOD_NOT_ALLOWED;
    public const HTTP_UNPROCESSABLE_ENTITY = ErrorCodes::VALIDATION_ERROR;
    public const HTTP_TOO_MANY_REQUESTS = ErrorCodes::RATE_LIMIT_EXCEEDED;
    public const HTTP_INTERNAL_SERVER_ERROR = ErrorCodes::INTERNAL_SERVER_ERROR;
    public const HTTP_SERVICE_UNAVAILABLE = ErrorCodes::SERVICE_UNAVAILABLE;

    public function __construct(
        mixed $data = null,
        int $status = ErrorCodes::SUCCESS,
        array $headers = [],
        bool $json = false
    ) {
        parent::__construct($data, $status, $headers, $json);

        // Set consistent JSON encoding options
        $this->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Set the serializer instance
     */
    public static function setSerializer(Serializer $serializer): void
    {
        self::$serializer = $serializer;
    }

    /**
     * Create successful response with optional serialization
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        ?SerializationContext $context = null
    ): self {
        $responseData = [
            'success' => true,
            'message' => $message,
            'data' => $data ?? []
        ];

        if (self::$serializer && $context && $data !== null) {
            $responseData['data'] = self::$serializer->normalize($data, $context);
        }

        return new self($responseData);
    }

    /**
     * Create paginated response with serialization support
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        ?SerializationContext $context = null,
        string $message = 'Data retrieved successfully'
    ): self {
        // Serialize items if context is provided
        $serializedItems = $items;
        if (self::$serializer && $context) {
            $serializedItems = array_map(
                fn($item) => self::$serializer->normalize($item, $context),
                $items
            );
        }

        // Create flattened response structure
        $responseData = [
            'success' => true,
            'message' => $message,
            'data' => $serializedItems,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage),
            'has_next_page' => $page < ceil($total / $perPage),
            'has_previous_page' => $page > 1
        ];

        return new self($responseData);
    }

    /**
     * Create created response (HTTP 201)
     */
    public static function created(
        mixed $data = null,
        string $message = 'Created successfully',
        ?SerializationContext $context = null
    ): self {
        $responseData = [
            'success' => true,
            'message' => $message,
            'data' => $data ?? []
        ];

        if (self::$serializer && $context && $data !== null) {
            $responseData['data'] = self::$serializer->normalize($data, $context);
        }

        return new self($responseData, self::HTTP_CREATED);
    }

    /**
     * Create no content response (HTTP 204)
     */
    public static function noContent(): self
    {
        return new self(null, self::HTTP_NO_CONTENT);
    }

    /**
     * Create error response
     */
    public static function error(
        string $message,
        int $status = self::HTTP_BAD_REQUEST,
        mixed $details = null
    ): self {
        $errorData = [
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $status,
                'timestamp' => date('c'),
                'request_id' => 'req_' . bin2hex(random_bytes(6))
            ]
        ];

        if ($details !== null) {
            $errorData['error']['details'] = $details;
        }

        return new self($errorData, $status);
    }

    /**
     * Create validation error response (HTTP 422)
     */
    public static function validation(
        array $errors,
        string $message = 'Validation failed'
    ): self {
        return self::error($message, self::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    /**
     * Create not found response (HTTP 404)
     */
    public static function notFound(string $message = 'Resource not found'): self
    {
        return self::error($message, self::HTTP_NOT_FOUND);
    }

    /**
     * Create unauthorized response (HTTP 401)
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, self::HTTP_UNAUTHORIZED);
    }

    /**
     * Create forbidden response (HTTP 403)
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, self::HTTP_FORBIDDEN);
    }

    /**
     * Create rate limit exceeded response (HTTP 429)
     */
    public static function rateLimited(string $message = 'Rate limit exceeded'): self
    {
        return self::error($message, self::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Create server error response (HTTP 500)
     */
    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::error($message, self::HTTP_INTERNAL_SERVER_ERROR);
    }


    /**
     * Create successful response with custom metadata (flattened structure)
     */
    public static function successWithMeta(
        array $data,
        array $meta,
        string $message = 'Data retrieved successfully',
        ?SerializationContext $context = null
    ): self {
        // Serialize data if context is provided
        $serializedData = $data;
        if (self::$serializer && $context) {
            $serializedData = array_map(
                fn($item) => self::$serializer->normalize($item, $context),
                $data
            );
        }

        // Build response with metadata at root level
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $serializedData
        ];

        // Merge all meta fields directly into the response (flattened)
        foreach ($meta as $key => $value) {
            $response[$key] = $value;
        }

        return new self($response);
    }


    /**
     * Add cache headers to response
     */
    public function withCacheHeaders(int $maxAge = 3600, bool $public = true): self
    {
        $this->headers->set('Cache-Control', $public ? "public, max-age={$maxAge}" : "private, max-age={$maxAge}");
        $this->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');

        return $this;
    }

    /**
     * Add ETag header for caching
     */
    public function withETag(string $etag): self
    {
        $this->headers->set('ETag', '"' . $etag . '"');
        return $this;
    }

    /**
     * Add CORS headers
     */
    public function withCors(
        array $allowedOrigins = ['*'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization']
    ): self {
        $this->headers->set('Access-Control-Allow-Origin', implode(', ', $allowedOrigins));
        $this->headers->set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
        $this->headers->set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));

        return $this;
    }

    /**
     * Set response as downloadable file
     */
    public function asDownload(string $filename, string $mimeType = 'application/octet-stream'): self
    {
        $this->headers->set('Content-Type', $mimeType);
        $this->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $this;
    }
}
