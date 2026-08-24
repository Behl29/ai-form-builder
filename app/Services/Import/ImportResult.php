<?php

namespace App\Services\Import;

/**
 * Result of document import parsing
 */
class ImportResult
{
    public function __construct(
        public bool $success,
        public array $elements = [],
        public array $errors = [],
        public array $warnings = [],
        public array $metadata = [],
        public ?string $suggestedTitle = null,
    ) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function getUnparseableElements(): array
    {
        return array_filter($this->elements, fn($e) => !$e->parseable);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'elements' => array_map(fn($e) => $e->toArray(), $this->elements),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
            'suggested_title' => $this->suggestedTitle,
        ];
    }

    public static function failure(array $errors): self
    {
        return new self(success: false, errors: $errors);
    }
}
