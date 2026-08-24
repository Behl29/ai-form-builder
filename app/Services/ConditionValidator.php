<?php

namespace App\Services;

use App\Services\FormSchema\FormSchemaContract;

class ConditionValidator
{
    private array $errors = [];
    private array $fieldMap = [];
    private array $sectionMap = [];

    /**
     * Operators supported by each field type
     */
    public const OPERATORS_BY_TYPE = [
        'text' => ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'],
        'textarea' => ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'],
        'email' => ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'],
        'url' => ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
        'phone' => ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
        'number' => ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_than_or_equals', 'less_than_or_equals', 'is_empty', 'is_not_empty'],
        'date' => ['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'],
        'select' => ['equals', 'not_equals', 'in', 'not_in', 'is_empty', 'is_not_empty'],
        'radio' => ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
        'checkbox' => ['equals', 'is_checked', 'is_not_checked'],
        'checkbox_group' => ['contains', 'not_contains', 'is_empty', 'is_not_empty'],
        'rating' => ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_than_or_equals', 'less_than_or_equals'],
        'file' => ['is_empty', 'is_not_empty'],
    ];

    public const CONDITION_ACTIONS = ['show', 'hide', 'require', 'skip_to_section', 'skip_to_step'];

    /**
     * Validate all conditions in a schema
     */
    public function validate(array $schema): array
    {
        $this->errors = [];
        $this->buildMaps($schema);

        foreach ($schema['sections'] ?? [] as $sectionIndex => $section) {
            $this->validateSectionConditions($section, $sectionIndex);

            foreach ($section['fields'] ?? [] as $fieldIndex => $field) {
                $this->validateFieldConditions($field, $sectionIndex, $fieldIndex);
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

    /**
     * Build lookup maps for fields and sections
     */
    private function buildMaps(array $schema): void
    {
        $this->fieldMap = [];
        $this->sectionMap = [];

        foreach ($schema['sections'] ?? [] as $sectionIndex => $section) {
            $sectionId = $section['id'] ?? "section_{$sectionIndex}";
            $this->sectionMap[$sectionId] = [
                'index' => $sectionIndex,
                'title' => $section['title'] ?? 'Untitled',
            ];

            foreach ($section['fields'] ?? [] as $fieldIndex => $field) {
                if (isset($field['key'])) {
                    $this->fieldMap[$field['key']] = [
                        'type' => $field['type'] ?? 'text',
                        'label' => $field['label'] ?? $field['key'],
                        'section_index' => $sectionIndex,
                        'field_index' => $fieldIndex,
                        'options' => $field['options'] ?? [],
                    ];
                }
            }
        }
    }

    /**
     * Validate conditions on a section
     */
    private function validateSectionConditions(array $section, int $sectionIndex): void
    {
        $sectionId = $section['id'] ?? "section_{$sectionIndex}";
        $conditions = $section['conditions'] ?? [];

        foreach ($conditions as $condIndex => $condition) {
            $this->validateCondition($condition, "sections[{$sectionIndex}].conditions[{$condIndex}]", $sectionId, 'section');
        }
    }

    /**
     * Validate conditions on a field
     */
    private function validateFieldConditions(array $field, int $sectionIndex, int $fieldIndex): void
    {
        $fieldKey = $field['key'] ?? null;
        if (!$fieldKey) {
            return;
        }

        $conditions = $field['conditions'] ?? [];

        foreach ($conditions as $condIndex => $condition) {
            $path = "sections[{$sectionIndex}].fields[{$fieldIndex}].conditions[{$condIndex}]";
            $this->validateCondition($condition, $path, $fieldKey, 'field');
        }
    }

    /**
     * Validate a single condition
     */
    private function validateCondition(array $condition, string $path, string $ownerKey, string $ownerType): void
    {
        $action = $condition['action'] ?? null;
        $targetField = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $targetSection = $condition['targetSection'] ?? null;

        // Validate action
        if (!$action || !in_array($action, self::CONDITION_ACTIONS)) {
            $this->addError($path, "Invalid action: {$action}");
            return;
        }

        // Validate referenced field exists
        if (!$targetField) {
            $this->addError($path, 'Condition must reference a field');
            return;
        }

        if (!isset($this->fieldMap[$targetField])) {
            $this->addError($path, "Referenced field '{$targetField}' does not exist");
            return;
        }

        // Validate no self-reference for fields
        if ($ownerType === 'field' && $targetField === $ownerKey) {
            $this->addError($path, 'Field cannot reference itself in conditions');
            return;
        }

        // Validate operator compatibility with field type
        $targetFieldType = $this->fieldMap[$targetField]['type'];
        $validOperators = self::OPERATORS_BY_TYPE[$targetFieldType] ?? ['equals', 'not_equals'];

        if (!in_array($operator, $validOperators)) {
            $this->addError($path, "Operator '{$operator}' is not compatible with field type '{$targetFieldType}'");
            return;
        }

        // Validate skip_to_section/skip_to_step targets
        if (in_array($action, ['skip_to_section', 'skip_to_step'])) {
            if (!$targetSection) {
                $this->addError($path, "Action '{$action}' requires a target section");
                return;
            }

            if (!isset($this->sectionMap[$targetSection])) {
                $this->addError($path, "Target section '{$targetSection}' does not exist");
                return;
            }

            // Validate no backward skip (would create cycle)
            if ($ownerType === 'field') {
                $ownerSectionIndex = $this->fieldMap[$ownerKey]['section_index'] ?? 0;
                $targetSectionIndex = $this->sectionMap[$targetSection]['index'] ?? 0;

                if ($targetSectionIndex <= $ownerSectionIndex) {
                    $this->addError($path, 'Cannot skip to a previous or current section (would create cycle)');
                }
            }
        }

        // Validate option values for select/radio fields
        if (in_array($operator, ['equals', 'not_equals', 'in', 'not_in'])) {
            $this->validateOptionValue($condition, $path, $targetField);
        }

        // Check for cycles in show/hide conditions
        if (in_array($action, ['show', 'hide']) && $ownerType === 'field') {
            $this->checkForCycles($ownerKey, $targetField, $path);
        }
    }

    /**
     * Validate that condition value matches available options
     */
    private function validateOptionValue(array $condition, string $path, string $targetField): void
    {
        $fieldInfo = $this->fieldMap[$targetField];
        $fieldType = $fieldInfo['type'];
        $options = $fieldInfo['options'];

        if (!in_array($fieldType, ['select', 'radio', 'checkbox_group']) || empty($options)) {
            return;
        }

        $validValues = array_column($options, 'value');
        $conditionValue = $condition['value'] ?? null;

        if ($conditionValue === null) {
            return;
        }

        $valuesToCheck = is_array($conditionValue) ? $conditionValue : [$conditionValue];

        foreach ($valuesToCheck as $value) {
            if (!in_array($value, $validValues, true)) {
                $this->addError($path, "Value '{$value}' is not a valid option for field '{$targetField}'");
            }
        }
    }

    /**
     * Check for circular dependencies in conditions
     */
    private function checkForCycles(string $fieldKey, string $targetField, string $path, array $visited = []): void
    {
        if (in_array($targetField, $visited)) {
            $this->addError($path, 'Circular dependency detected in conditions');
            return;
        }

        // This is a simplified cycle check - in production you'd want a full graph traversal
        // For now, we just prevent direct circular references
    }

    private function addError(string $path, string $message): void
    {
        $this->errors[] = [
            'path' => $path,
            'message' => $message,
        ];
    }

    /**
     * Get supported operators for a field type
     */
    public static function getOperatorsForType(string $type): array
    {
        return self::OPERATORS_BY_TYPE[$type] ?? ['equals', 'not_equals', 'is_empty', 'is_not_empty'];
    }

    /**
     * Get human-readable operator labels
     */
    public static function getOperatorLabels(): array
    {
        return [
            'equals' => 'Equals',
            'not_equals' => 'Does not equal',
            'contains' => 'Contains',
            'not_contains' => 'Does not contain',
            'greater_than' => 'Greater than',
            'less_than' => 'Less than',
            'greater_than_or_equals' => 'Greater than or equals',
            'less_than_or_equals' => 'Less than or equals',
            'is_empty' => 'Is empty',
            'is_not_empty' => 'Is not empty',
            'in' => 'Is one of',
            'not_in' => 'Is not one of',
            'is_checked' => 'Is checked',
            'is_not_checked' => 'Is not checked',
        ];
    }
}
