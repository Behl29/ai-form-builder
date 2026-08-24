<?php

namespace App\Services;

use Exception;

class SubmissionValidationException extends Exception
{
    public function __construct(
        private array $errors,
        string $message = 'Validation failed'
    ) {
        parent::__construct($message);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
