<?php

namespace Glueful\Api\Http;

class Response
{
    // HTTP Status Codes
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    private int $statusCode;
    private mixed $data;
    private ?string $message;
    private bool $success;

    public function __construct(
        mixed $data = null,
        int $statusCode = self::HTTP_OK,
        ?string $message = null,
        bool $success = true
    ) {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->message = $message;
        $this->success = $success;
    }

    public function send(): array
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');

        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'code' => $this->statusCode
        ];
    }

    public static function ok(mixed $data = null, ?string $message = null): self
    {
        return new self($data, self::HTTP_OK, $message);
    }

    public static function created(mixed $data = null, ?string $message = null): self
    {
        return new self($data, self::HTTP_CREATED, $message);
    }

    public static function error(string $message, int $statusCode = self::HTTP_BAD_REQUEST): self
    {
        return new self(null, $statusCode, $message, false);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self(null, self::HTTP_NOT_FOUND, $message, false);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(null, self::HTTP_UNAUTHORIZED, $message, false);
    }
}
