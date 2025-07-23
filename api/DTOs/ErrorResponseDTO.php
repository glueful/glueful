<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Serialization\Attributes\{Groups, SerializedName, DateFormat, Ignore};

/**
 * Error Response DTO
 *
 * Standardized error response with controlled information exposure
 * based on environment and user permissions.
 */
class ErrorResponseDTO
{
    #[Groups(['error', 'public'])]
    public bool $success = false;

    #[Groups(['error', 'public'])]
    public string $error;

    #[Groups(['error', 'public'])]
    public string $message;

    #[Groups(['error', 'public'])]
    public int $code;

    #[Groups(['error', 'detailed'])]
    public ?string $type = null;

    #[Groups(['error', 'detailed'])]
    public ?array $details = null;

    #[Groups(['error', 'detailed'])]
    public ?array $context = null;

    #[Groups(['error', 'validation'])]
    public ?array $validation = null;

    #[Groups(['error', 'debug'])]
    public ?string $file = null;

    #[Groups(['error', 'debug'])]
    public ?int $line = null;

    #[Groups(['error', 'debug'])]
    public ?array $trace = null;

    #[Groups(['error', 'debug'])]
    public ?array $previous = null;

    #[Groups(['error', 'debug'])]
    #[SerializedName('request_id')]
    public ?string $requestId = null;

    #[Groups(['error', 'debug'])]
    #[SerializedName('user_id')]
    public ?string $userId = null;

    #[Groups(['error', 'debug'])]
    #[SerializedName('request_uri')]
    public ?string $requestUri = null;

    #[Groups(['error', 'debug'])]
    #[SerializedName('request_method')]
    public ?string $requestMethod = null;

    #[Groups(['error', 'debug'])]
    #[SerializedName('user_agent')]
    public ?string $userAgent = null;

    #[Groups(['error', 'debug'])]
    #[SerializedName('ip_address')]
    public ?string $ipAddress = null;

    #[Groups(['error', 'public'])]
    #[SerializedName('timestamp')]
    #[DateFormat('c')]
    public \DateTime $timestamp;

    #[Ignore] // Never serialize the original exception
    public ?\Throwable $originalException = null;

    public function __construct(
        string $error,
        string $message,
        int $code = 500,
        ?string $type = null
    ) {
        $this->error = $error;
        $this->message = $message;
        $this->code = $code;
        $this->type = $type;
        $this->timestamp = new \DateTime();
    }

    /**
     * Create from exception
     */
    public static function fromException(\Throwable $exception, bool $includeTrace = false): self
    {
        $error = new self(
            $exception::class,
            $exception->getMessage(),
            $exception->getCode() ?: 500,
            'exception'
        );

        $error->originalException = $exception;
        $error->file = $exception->getFile();
        $error->line = $exception->getLine();

        if ($includeTrace) {
            $error->trace = array_slice($exception->getTrace(), 0, 10); // Limit trace
        }

        // Handle previous exceptions
        if ($exception->getPrevious()) {
            $error->previous = [
                'class' => $exception->getPrevious()::class,
                'message' => $exception->getPrevious()->getMessage(),
                'code' => $exception->getPrevious()->getCode(),
            ];
        }

        return $error;
    }

    /**
     * Create validation error
     */
    public static function createValidationError(array $errors, string $message = 'Validation failed'): self
    {
        $error = new self(
            'ValidationError',
            $message,
            422,
            'validation'
        );

        $error->validation = $errors;
        return $error;
    }

    /**
     * Create authentication error
     */
    public static function authentication(string $message = 'Authentication required'): self
    {
        return new self(
            'AuthenticationError',
            $message,
            401,
            'authentication'
        );
    }

    /**
     * Create authorization error
     */
    public static function authorization(string $message = 'Access denied'): self
    {
        return new self(
            'AuthorizationError',
            $message,
            403,
            'authorization'
        );
    }

    /**
     * Create not found error
     */
    public static function notFound(string $resource = 'Resource', string $identifier = null): self
    {
        $message = $identifier
            ? "{$resource} with identifier '{$identifier}' not found"
            : "{$resource} not found";

        return new self(
            'NotFoundError',
            $message,
            404,
            'not_found'
        );
    }

    /**
     * Create rate limit error
     */
    public static function rateLimit(int $retryAfter = null): self
    {
        $error = new self(
            'RateLimitError',
            'Rate limit exceeded',
            429,
            'rate_limit'
        );

        if ($retryAfter) {
            $error->details = ['retry_after' => $retryAfter];
        }

        return $error;
    }

    /**
     * Create server error
     */
    public static function server(string $message = 'Internal server error'): self
    {
        return new self(
            'ServerError',
            $message,
            500,
            'server'
        );
    }

    /**
     * Add request context
     */
    public function withRequestContext(
        string $requestId,
        string $method,
        string $uri,
        ?string $userId = null,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): self {
        $this->requestId = $requestId;
        $this->requestMethod = $method;
        $this->requestUri = $uri;
        $this->userId = $userId;
        $this->userAgent = $userAgent;
        $this->ipAddress = $ipAddress;
        return $this;
    }

    /**
     * Add additional details
     */
    public function withDetails(array $details): self
    {
        $this->details = array_merge($this->details ?? [], $details);
        return $this;
    }

    /**
     * Add context information
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context ?? [], $context);
        return $this;
    }

    /**
     * Get HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return match (true) {
            $this->code >= 400 && $this->code < 600 => $this->code,
            default => 500
        };
    }

    /**
     * Check if this is a client error
     */
    public function isClientError(): bool
    {
        return $this->getHttpStatusCode() >= 400 && $this->getHttpStatusCode() < 500;
    }

    /**
     * Check if this is a server error
     */
    public function isServerError(): bool
    {
        return $this->getHttpStatusCode() >= 500;
    }

    /**
     * Get safe message for production
     */
    public function getSafeMessage(): string
    {
        // In production, sanitize certain error messages
        if ($this->isServerError() && !app()->isDebugMode()) {
            return 'An internal error occurred. Please try again later.';
        }

        return $this->message;
    }

    /**
     * Get error summary for logging
     */
    public function getSummary(): array
    {
        return [
            'error' => $this->error,
            'message' => $this->message,
            'code' => $this->code,
            'type' => $this->type,
            'file' => $this->file,
            'line' => $this->line,
            'request_id' => $this->requestId,
            'timestamp' => $this->timestamp->format('c'),
        ];
    }
}
