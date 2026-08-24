<?php

namespace App\Exceptions;

use Exception;

class SchemaValidationException extends Exception
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Schema validation failed')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];
    }
}
