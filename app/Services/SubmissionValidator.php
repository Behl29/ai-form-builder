<?php

namespace App\Services;

use App\Services\FormSchema\FormSchemaContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class SubmissionValidator
{
    private array $errors = [];
    private array $schema;
    private array $data;
    private array $files;

    public function validate(array $schema, array $data, array $files = []): array
    {
        $this->errors = [];
        $this->schema = $schema;
        $this->data = $data;
        $this->files = $files;

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $this->validateField($field);
            }
        }

        return $this->errors;
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function validateField(array $field): void
    {
        $key = $field['key'] ?? null;
        $type = $field['type'] ?? null;

        if (!$key || !$type) {
            return;
        }

        // Skip presentational fields
        if (in_array($type, FormSchemaContract::PRESENTATIONAL_FIELDS)) {
            return;
        }

        // Check if field should be visible based on conditions
        if (!$this->isFieldVisible($field)) {
            return;
        }

        $value = $this->data[$key] ?? null;
        $label = $field['label'] ?? $key;
        $customError = $field['customError'] ?? null;

        // Required validation
        $isRequired = $field['required'] ?? false;
        if ($this->shouldBeRequired($field) || $isRequired) {
            if ($this->isEmpty($value, $type)) {
                $this->addError($key, $customError ?? "{$label} is required.");
                return;
            }
        }

        // Skip further validation if empty and not required
        if ($this->isEmpty($value, $type)) {
            return;
        }

        // Type-specific validation
        match ($type) {
            'text', 'textarea' => $this->validateText($field, $value, $label, $customError),
            'number' => $this->validateNumber($field, $value, $label, $customError),
            'email' => $this->validateEmail($field, $value, $label, $customError),
            'url' => $this->validateUrl($field, $value, $label, $customError),
            'phone' => $this->validatePhone($field, $value, $label, $customError),
            'date' => $this->validateDate($field, $value, $label, $customError),
            'select', 'radio' => $this->validateOption($field, $value, $label, $customError),
            'checkbox_group' => $this->validateCheckboxGroup($field, $value, $label, $customError),
            'checkbox' => $this->validateCheckbox($field, $value, $label, $customError),
            'file' => $this->validateFile($field, $key, $label, $customError),
            'rating' => $this->validateRating($field, $value, $label, $customError),
            default => null,
        };
    }

    private function validateText(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!is_string($value)) {
            $this->addError($field['key'], $customError ?? "{$label} must be text.");
            return;
        }

        $minLength = $field['minLength'] ?? null;
        $maxLength = $field['maxLength'] ?? null;
        $pattern = $field['pattern'] ?? null;

        if ($minLength !== null && mb_strlen($value) < $minLength) {
            $this->addError($field['key'], $customError ?? "{$label} must be at least {$minLength} characters.");
        }

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            $this->addError($field['key'], $customError ?? "{$label} must not exceed {$maxLength} characters.");
        }

        if ($pattern !== null && !preg_match("/{$pattern}/", $value)) {
            $this->addError($field['key'], $customError ?? "{$label} format is invalid.");
        }
    }

    private function validateNumber(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!is_numeric($value)) {
            $this->addError($field['key'], $customError ?? "{$label} must be a number.");
            return;
        }

        $numValue = (float) $value;
        $min = $field['min'] ?? null;
        $max = $field['max'] ?? null;

        if ($min !== null && $numValue < $min) {
            $this->addError($field['key'], $customError ?? "{$label} must be at least {$min}.");
        }

        if ($max !== null && $numValue > $max) {
            $this->addError($field['key'], $customError ?? "{$label} must not exceed {$max}.");
        }
    }

    private function validateEmail(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field['key'], $customError ?? "{$label} must be a valid email address.");
        }
    }

    private function validateUrl(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field['key'], $customError ?? "{$label} must be a valid URL.");
        }
    }

    private function validatePhone(array $field, mixed $value, string $label, ?string $customError): void
    {
        $pattern = $field['pattern'] ?? '^\+?[0-9\s\-\(\)]+$';
        if (!preg_match("/{$pattern}/", $value)) {
            $this->addError($field['key'], $customError ?? "{$label} must be a valid phone number.");
        }
    }

    private function validateDate(array $field, mixed $value, string $label, ?string $customError): void
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $this->addError($field['key'], $customError ?? "{$label} must be a valid date.");
            return;
        }

        $min = $field['min'] ?? null;
        $max = $field['max'] ?? null;

        if ($min !== null) {
            $minDate = \DateTime::createFromFormat('Y-m-d', $min);
            if ($minDate && $date < $minDate) {
                $this->addError($field['key'], $customError ?? "{$label} must be on or after {$min}.");
            }
        }

        if ($max !== null) {
            $maxDate = \DateTime::createFromFormat('Y-m-d', $max);
            if ($maxDate && $date > $maxDate) {
                $this->addError($field['key'], $customError ?? "{$label} must be on or before {$max}.");
            }
        }
    }

    private function validateOption(array $field, mixed $value, string $label, ?string $customError): void
    {
        $options = $field['options'] ?? [];
        $validValues = array_column($options, 'value');

        if (!in_array($value, $validValues, false)) {
            $this->addError($field['key'], $customError ?? "{$label} contains an invalid selection.");
        }
    }

    private function validateCheckboxGroup(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!is_array($value)) {
            $this->addError($field['key'], $customError ?? "{$label} must be an array of selections.");
            return;
        }

        $options = $field['options'] ?? [];
        $validValues = array_column($options, 'value');

        foreach ($value as $v) {
            if (!in_array($v, $validValues, false)) {
                $this->addError($field['key'], $customError ?? "{$label} contains an invalid selection.");
                return;
            }
        }

        $minSelected = $field['minSelected'] ?? null;
        $maxSelected = $field['maxSelected'] ?? null;

        if ($minSelected !== null && count($value) < $minSelected) {
            $this->addError($field['key'], $customError ?? "{$label} requires at least {$minSelected} selections.");
        }

        if ($maxSelected !== null && count($value) > $maxSelected) {
            $this->addError($field['key'], $customError ?? "{$label} allows at most {$maxSelected} selections.");
        }
    }

    private function validateCheckbox(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            $this->addError($field['key'], $customError ?? "{$label} must be checked or unchecked.");
        }
    }

    private function validateFile(array $field, string $key, string $label, ?string $customError): void
    {
        $files = $this->files[$key] ?? null;

        if ($files === null) {
            return;
        }

        $fileList = is_array($files) ? $files : [$files];
        $accept = $field['accept'] ?? [];
        $maxSize = $field['maxSize'] ?? null;
        $maxFiles = $field['maxFiles'] ?? null;
        $multiple = $field['multiple'] ?? false;

        if (!$multiple && count($fileList) > 1) {
            $this->addError($key, $customError ?? "{$label} only allows one file.");
            return;
        }

        if ($maxFiles !== null && count($fileList) > $maxFiles) {
            $this->addError($key, $customError ?? "{$label} allows at most {$maxFiles} files.");
            return;
        }

        foreach ($fileList as $file) {
            if (!($file instanceof UploadedFile)) {
                continue;
            }

            if (!$file->isValid()) {
                $this->addError($key, $customError ?? "{$label} upload failed.");
                continue;
            }

            // Extension validation
            if (!empty($accept)) {
                $extension = '.' . strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType();
                $isValidType = false;

                foreach ($accept as $accepted) {
                    if (str_starts_with($accepted, '.')) {
                        if (strtolower($accepted) === $extension) {
                            $isValidType = true;
                            break;
                        }
                    } elseif (str_contains($accepted, '/')) {
                        if (str_ends_with($accepted, '/*')) {
                            $baseType = str_replace('/*', '', $accepted);
                            if (str_starts_with($mimeType, $baseType)) {
                                $isValidType = true;
                                break;
                            }
                        } elseif ($mimeType === $accepted) {
                            $isValidType = true;
                            break;
                        }
                    }
                }

                if (!$isValidType) {
                    $this->addError($key, $customError ?? "{$label} must be one of: " . implode(', ', $accept));
                }
            }

            // Size validation
            if ($maxSize !== null && $file->getSize() > $maxSize) {
                $maxSizeMB = round($maxSize / (1024 * 1024), 2);
                $this->addError($key, $customError ?? "{$label} must not exceed {$maxSizeMB} MB.");
            }
        }
    }

    private function validateRating(array $field, mixed $value, string $label, ?string $customError): void
    {
        if (!is_numeric($value)) {
            $this->addError($field['key'], $customError ?? "{$label} must be a number.");
            return;
        }

        $numValue = (float) $value;
        $min = $field['min'] ?? 1;
        $max = $field['max'] ?? 5;

        if ($numValue < $min || $numValue > $max) {
            $this->addError($field['key'], $customError ?? "{$label} must be between {$min} and {$max}.");
        }
    }

    private function isEmpty(mixed $value, string $type): bool
    {
        if ($value === null) {
            return true;
        }

        if ($type === 'file') {
            return empty($this->files);
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    private function isFieldVisible(array $field): bool
    {
        $conditions = $field['conditions'] ?? [];

        foreach ($conditions as $condition) {
            $action = $condition['action'] ?? null;
            if (!in_array($action, ['show', 'hide'])) {
                continue;
            }

            $targetFieldKey = $condition['field'] ?? null;
            if (!$targetFieldKey) {
                continue;
            }

            $targetValue = $this->data[$targetFieldKey] ?? null;
            $conditionMet = $this->evaluateCondition($condition, $targetValue);

            if ($action === 'show' && !$conditionMet) {
                return false;
            }

            if ($action === 'hide' && $conditionMet) {
                return false;
            }
        }

        return true;
    }

    private function shouldBeRequired(array $field): bool
    {
        $conditions = $field['conditions'] ?? [];

        foreach ($conditions as $condition) {
            if (($condition['action'] ?? null) !== 'require') {
                continue;
            }

            $targetFieldKey = $condition['field'] ?? null;
            if (!$targetFieldKey) {
                continue;
            }

            $targetValue = $this->data[$targetFieldKey] ?? null;
            if ($this->evaluateCondition($condition, $targetValue)) {
                return true;
            }
        }

        return false;
    }

    private function evaluateCondition(array $condition, mixed $value): bool
    {
        $operator = $condition['operator'] ?? 'equals';
        $conditionValue = $condition['value'] ?? null;

        return match ($operator) {
            'equals' => $value == $conditionValue,
            'not_equals' => $value != $conditionValue,
            'contains' => is_string($value) && str_contains($value, (string) $conditionValue),
            'not_contains' => is_string($value) && !str_contains($value, (string) $conditionValue),
            'greater_than' => is_numeric($value) && $value > $conditionValue,
            'less_than' => is_numeric($value) && $value < $conditionValue,
            'is_empty' => $value === null || $value === '' || (is_array($value) && empty($value)),
            'is_not_empty' => $value !== null && $value !== '' && !(is_array($value) && empty($value)),
            'in' => is_array($conditionValue) && in_array($value, $conditionValue),
            'not_in' => is_array($conditionValue) && !in_array($value, $conditionValue),
            default => false,
        };
    }

    private function addError(string $key, string $message): void
    {
        if (!isset($this->errors[$key])) {
            $this->errors[$key] = [];
        }
        $this->errors[$key][] = $message;
    }
}
