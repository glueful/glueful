<?php
declare(strict_types=1);

namespace Glueful\Api\Exceptions;

class ValidationException extends ApiException
{
    private array $errors;

    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422, $errors);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}