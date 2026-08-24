<?php

namespace App\Services\FormSchema;

use App\Exceptions\SchemaValidationException;

class FormSchemaValidator
{
    private array $errors = [];
    private array $fieldIds = [];
    private array $fieldKeys = [];

    public function validate(array $schema): bool
    {
        $this->errors = [];
        $this->fieldIds = [];
        $this->fieldKeys = [];

        $this->validateSchemaSize($schema);
        $this->validateSchemaVersion($schema);
        $this->validateMetadata($schema);
        $this->validateSettings($schema);
        $this->validateSections($schema);

        if (!empty($this->errors)) {
            throw new SchemaValidationException($this->errors);
        }

        return true;
    }

    /**
     * Validate and return errors without throwing
     */
    public function validateAndGetErrors(array $schema): array
    {
        $this->errors = [];
        $this->fieldIds = [];
        $this->fieldKeys = [];

        $this->validateSchemaSize($schema);
        $this->validateSchemaVersion($schema);
        $this->validateMetadata($schema);
        $this->validateSettings($schema);
        $this->validateSections($schema);

        return $this->errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function addError(string $path, string $message, string $code = 'invalid'): void
    {
        $this->errors[] = [
            'path' => $path,
            'message' => $message,
            'code' => $code,
        ];
    }

    private function validateSchemaSize(array $schema): void
    {
        $size = strlen(json_encode($schema));
        if ($size > FormSchemaContract::MAX_SCHEMA_SIZE_BYTES) {
            $this->addError('$', 'Schema exceeds maximum size of 1MB', 'schema_too_large');
        }
    }

    private function validateSchemaVersion(array $schema): void
    {
        if (!isset($schema['schemaVersion'])) {
            $this->addError('schemaVersion', 'Schema version is required', 'required');
            return;
        }

        if ($schema['schemaVersion'] !== FormSchemaContract::SCHEMA_VERSION) {
            $this->addError(
                'schemaVersion',
                "Unsupported schema version: {$schema['schemaVersion']}. Expected: " . FormSchemaContract::SCHEMA_VERSION,
                'unsupported_version'
            );
        }
    }

    private function validateMetadata(array $schema): void
    {
        if (!isset($schema['metadata'])) {
            $this->addError('metadata', 'Metadata is required', 'required');
            return;
        }

        if (!is_array($schema['metadata'])) {
            $this->addError('metadata', 'Metadata must be an object', 'invalid_type');
            return;
        }

        if (!isset($schema['metadata']['title']) || !is_string($schema['metadata']['title'])) {
            $this->addError('metadata.title', 'Title is required and must be a string', 'required');
        }
    }

    private function validateSettings(array $schema): void
    {
        if (isset($schema['settings']) && !is_array($schema['settings'])) {
            $this->addError('settings', 'Settings must be an object', 'invalid_type');
        }
    }

    private function validateSections(array $schema): void
    {
        if (!isset($schema['sections'])) {
            $this->addError('sections', 'Sections array is required', 'required');
            return;
        }

        if (!is_array($schema['sections'])) {
            $this->addError('sections', 'Sections must be an array', 'invalid_type');
            return;
        }

        if (count($schema['sections']) > FormSchemaContract::MAX_SECTION_COUNT) {
            $this->addError('sections', 'Exceeds maximum section count of ' . FormSchemaContract::MAX_SECTION_COUNT, 'too_many_sections');
        }

        $sectionIds = [];
        $totalFields = 0;

        foreach ($schema['sections'] as $index => $section) {
            $path = "sections[{$index}]";

            if (!is_array($section)) {
                $this->addError($path, 'Section must be an object', 'invalid_type');
                continue;
            }

            if (!isset($section['id']) || !is_string($section['id'])) {
                $this->addError("{$path}.id", 'Section ID is required', 'required');
            } elseif (in_array($section['id'], $sectionIds)) {
                $this->addError("{$path}.id", "Duplicate section ID: {$section['id']}", 'duplicate_id');
            } else {
                $sectionIds[] = $section['id'];
            }

            if (isset($section['fields']) && is_array($section['fields'])) {
                $totalFields += count($section['fields']);
                $this->validateFields($section['fields'], $path);
            }
        }

        if ($totalFields > FormSchemaContract::MAX_FIELD_COUNT) {
            $this->addError('sections', 'Exceeds maximum field count of ' . FormSchemaContract::MAX_FIELD_COUNT, 'too_many_fields');
        }
    }

    private function validateFields(array $fields, string $sectionPath): void
    {
        foreach ($fields as $index => $field) {
            $path = "{$sectionPath}.fields[{$index}]";

            if (!is_array($field)) {
                $this->addError($path, 'Field must be an object', 'invalid_type');
                continue;
            }

            $this->validateFieldId($field, $path);
            $this->validateFieldKey($field, $path);
            $this->validateFieldType($field, $path);

            if (isset($field['type']) && in_array($field['type'], FormSchemaContract::FIELD_TYPES)) {
                $this->validateFieldProperties($field, $path);
                $this->validateFieldConditions($field, $path);
            }
        }
    }

    private function validateFieldId(array $field, string $path): void
    {
        if (!isset($field['id']) || !is_string($field['id']) || empty($field['id'])) {
            $this->addError("{$path}.id", 'Field ID is required', 'required');
            return;
        }

        if (in_array($field['id'], $this->fieldIds)) {
            $this->addError("{$path}.id", "Duplicate field ID: {$field['id']}", 'duplicate_id');
        } else {
            $this->fieldIds[] = $field['id'];
        }
    }

    private function validateFieldKey(array $field, string $path): void
    {
        if (!isset($field['key']) || !is_string($field['key']) || empty($field['key'])) {
            $this->addError("{$path}.key", 'Field key is required', 'required');
            return;
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $field['key'])) {
            $this->addError("{$path}.key", 'Field key must start with lowercase letter and contain only lowercase letters, numbers, and underscores', 'invalid_format');
            return;
        }

        if (in_array($field['key'], $this->fieldKeys)) {
            $this->addError("{$path}.key", "Duplicate field key: {$field['key']}", 'duplicate_key');
        } else {
            $this->fieldKeys[] = $field['key'];
        }
    }

    private function validateFieldType(array $field, string $path): void
    {
        if (!isset($field['type'])) {
            $this->addError("{$path}.type", 'Field type is required', 'required');
            return;
        }

        if (!in_array($field['type'], FormSchemaContract::FIELD_TYPES)) {
            $this->addError("{$path}.type", "Unsupported field type: {$field['type']}", 'unsupported_type');
        }
    }

    private function validateFieldProperties(array $field, string $path): void
    {
        $type = $field['type'];

        // Validate label for non-presentational fields
        if (!in_array($type, FormSchemaContract::PRESENTATIONAL_FIELDS)) {
            if (!isset($field['label']) || !is_string($field['label'])) {
                $this->addError("{$path}.label", 'Field label is required', 'required');
            }
        }

        // Validate options for fields that require them
        if (in_array($type, FormSchemaContract::FIELDS_WITH_OPTIONS)) {
            $this->validateFieldOptions($field, $path);
        }

        // Validate type-specific properties
        $this->validateTypeSpecificProperties($field, $path);
    }

    private function validateFieldOptions(array $field, string $path): void
    {
        if (!isset($field['options']) || !is_array($field['options'])) {
            $this->addError("{$path}.options", 'Options are required for this field type', 'required');
            return;
        }

        if (empty($field['options'])) {
            $this->addError("{$path}.options", 'At least one option is required', 'min_options');
            return;
        }

        if (count($field['options']) > FormSchemaContract::MAX_OPTION_COUNT) {
            $this->addError("{$path}.options", 'Exceeds maximum option count', 'too_many_options');
        }

        $optionValues = [];
        foreach ($field['options'] as $optIndex => $option) {
            $optPath = "{$path}.options[{$optIndex}]";

            if (!is_array($option)) {
                $this->addError($optPath, 'Option must be an object', 'invalid_type');
                continue;
            }

            if (!isset($option['value'])) {
                $this->addError("{$optPath}.value", 'Option value is required', 'required');
            } elseif (in_array($option['value'], $optionValues, true)) {
                $this->addError("{$optPath}.value", 'Duplicate option value', 'duplicate_value');
            } else {
                $optionValues[] = $option['value'];
            }

            if (!isset($option['label']) || !is_string($option['label'])) {
                $this->addError("{$optPath}.label", 'Option label is required', 'required');
            }
        }
    }

    private function validateTypeSpecificProperties(array $field, string $path): void
    {
        $type = $field['type'];

        switch ($type) {
            case 'number':
            case 'rating':
                if (isset($field['min']) && isset($field['max']) && $field['min'] > $field['max']) {
                    $this->addError("{$path}.min", 'Min cannot be greater than max', 'invalid_range');
                }
                break;

            case 'text':
            case 'textarea':
                if (isset($field['minLength']) && isset($field['maxLength']) && $field['minLength'] > $field['maxLength']) {
                    $this->addError("{$path}.minLength", 'minLength cannot be greater than maxLength', 'invalid_range');
                }
                break;

            case 'file':
                if (isset($field['maxSize']) && (!is_int($field['maxSize']) || $field['maxSize'] <= 0)) {
                    $this->addError("{$path}.maxSize", 'maxSize must be a positive integer', 'invalid_value');
                }
                break;

            case 'heading':
                if (isset($field['level']) && (!is_int($field['level']) || $field['level'] < 1 || $field['level'] > 6)) {
                    $this->addError("{$path}.level", 'Heading level must be between 1 and 6', 'invalid_value');
                }
                break;
        }
    }

    private function validateFieldConditions(array $field, string $path): void
    {
        if (!isset($field['conditions'])) {
            return;
        }

        if (!is_array($field['conditions'])) {
            $this->addError("{$path}.conditions", 'Conditions must be an array', 'invalid_type');
            return;
        }

        foreach ($field['conditions'] as $condIndex => $condition) {
            $condPath = "{$path}.conditions[{$condIndex}]";

            if (!is_array($condition)) {
                $this->addError($condPath, 'Condition must be an object', 'invalid_type');
                continue;
            }

            // Validate field reference
            if (!isset($condition['field'])) {
                $this->addError("{$condPath}.field", 'Condition field reference is required', 'required');
            } elseif ($condition['field'] === $field['id']) {
                $this->addError("{$condPath}.field", 'Field cannot reference itself in conditions', 'self_reference');
            }

            // Validate operator
            if (!isset($condition['operator'])) {
                $this->addError("{$condPath}.operator", 'Condition operator is required', 'required');
            } elseif (!in_array($condition['operator'], FormSchemaContract::CONDITION_OPERATORS)) {
                $this->addError("{$condPath}.operator", "Unsupported operator: {$condition['operator']}", 'unsupported_operator');
            }

            // Validate action
            if (!isset($condition['action'])) {
                $this->addError("{$condPath}.action", 'Condition action is required', 'required');
            } elseif (!in_array($condition['action'], FormSchemaContract::CONDITION_ACTIONS)) {
                $this->addError("{$condPath}.action", "Unsupported action: {$condition['action']}", 'unsupported_action');
            }

            // Value is required for most operators
            $noValueOperators = ['is_empty', 'is_not_empty'];
            if (isset($condition['operator']) && !in_array($condition['operator'], $noValueOperators) && !array_key_exists('value', $condition)) {
                $this->addError("{$condPath}.value", 'Condition value is required for this operator', 'required');
            }
        }
    }

    /**
     * Validate that all condition field references exist
     */
    public function validateConditionReferences(array $schema): void
    {
        $allFieldIds = $this->collectFieldIds($schema);

        foreach ($schema['sections'] ?? [] as $sIndex => $section) {
            foreach ($section['fields'] ?? [] as $fIndex => $field) {
                foreach ($field['conditions'] ?? [] as $cIndex => $condition) {
                    if (isset($condition['field']) && !in_array($condition['field'], $allFieldIds)) {
                        $this->addError(
                            "sections[{$sIndex}].fields[{$fIndex}].conditions[{$cIndex}].field",
                            "Referenced field does not exist: {$condition['field']}",
                            'invalid_reference'
                        );
                    }
                }
            }
        }

        if (!empty($this->errors)) {
            throw new SchemaValidationException($this->errors);
        }
    }

    private function collectFieldIds(array $schema): array
    {
        $ids = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['id'])) {
                    $ids[] = $field['id'];
                }
            }
        }
        return $ids;
    }
}
