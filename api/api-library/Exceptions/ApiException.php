<?php
declare(strict_types=1);

namespace Glueful\Api\Exceptions;

use Exception;

class ApiException extends Exception
{
    private array|null $data;

    public function __construct(string $message, int $statusCode = 400, array|null $data = null)
    {
        parent::__construct($message, $statusCode);
        $this->data = $data;
    }

    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    public function getData(): array|null
    {
        return $this->data;
    }
}