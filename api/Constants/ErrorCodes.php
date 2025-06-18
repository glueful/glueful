<?php

declare(strict_types=1);

namespace Glueful\Constants;

/**
 * Error Codes
 *
 * Centralized HTTP status codes and error constants for consistent
 * error handling across the application.
 *
 * @package Glueful\Constants
 */
class ErrorCodes
{
    // Success codes
    public const SUCCESS = 200;
    public const CREATED = 201;
    public const ACCEPTED = 202;
    public const NO_CONTENT = 204;

    // Client error codes
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const CONFLICT = 409;
    public const VALIDATION_ERROR = 422;
    public const RATE_LIMIT_EXCEEDED = 429;

    // Server error codes
    public const INTERNAL_SERVER_ERROR = 500;
    public const BAD_GATEWAY = 502;
    public const SERVICE_UNAVAILABLE = 503;
    public const GATEWAY_TIMEOUT = 504;

    // Application-specific error codes
    public const AUTHENTICATION_ERROR = 401;
    public const AUTHORIZATION_ERROR = 403;
    public const RESOURCE_NOT_FOUND = 404;
    public const DATABASE_ERROR = 500;
    public const BUSINESS_LOGIC_ERROR = 422;
    public const SECURITY_ERROR = 403;

    /**
     * Get error message for HTTP status code
     *
     * @param int $code HTTP status code
     * @return string Error message
     */
    public static function getMessage(int $code): string
    {
        return match ($code) {
            self::BAD_REQUEST => 'Bad Request',
            self::UNAUTHORIZED => 'Unauthorized',
            self::FORBIDDEN => 'Forbidden',
            self::NOT_FOUND => 'Not Found',
            self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
            self::CONFLICT => 'Conflict',
            self::VALIDATION_ERROR => 'Validation Error',
            self::RATE_LIMIT_EXCEEDED => 'Rate Limit Exceeded',
            self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
            self::BAD_GATEWAY => 'Bad Gateway',
            self::SERVICE_UNAVAILABLE => 'Service Unavailable',
            self::GATEWAY_TIMEOUT => 'Gateway Timeout',
            default => 'Unknown Error'
        };
    }

    /**
     * Check if status code indicates success
     *
     * @param int $code HTTP status code
     * @return bool True if success code (2xx)
     */
    public static function isSuccess(int $code): bool
    {
        return $code >= 200 && $code < 300;
    }

    /**
     * Check if status code indicates client error
     *
     * @param int $code HTTP status code
     * @return bool True if client error (4xx)
     */
    public static function isClientError(int $code): bool
    {
        return $code >= 400 && $code < 500;
    }

    /**
     * Check if status code indicates server error
     *
     * @param int $code HTTP status code
     * @return bool True if server error (5xx)
     */
    public static function isServerError(int $code): bool
    {
        return $code >= 500 && $code < 600;
    }
}
