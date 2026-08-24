<?php

namespace App\Services;

/**
 * Shared condition evaluation logic for preview, public renderer, and server validation.
 * This class is designed to have equivalent JavaScript implementation for frontend.
 */
class ConditionEvaluator
{
    private array $schema;
    private array $data;
    private array $fieldMap = [];
    private array $sectionMap = [];
    private array $visibilityCache = [];

    public function __construct(array $schema, array $data = [])
    {
        $this->schema = $schema;
        $this->data = $data;
        $this->buildMaps();
    }

    /**
     * Update form data (for re-evaluation)
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        $this->visibilityCache = []; // Clear cache when data changes
        return $this;
    }

    /**
     * Check if a field should be visible
     */
    public function isFieldVisible(string $fieldKey): bool
    {
        if (isset($this->visibilityCache["field:{$fieldKey}"])) {
            return $this->visibilityCache["field:{$fieldKey}"];
        }

        $field = $this->fieldMap[$fieldKey] ?? null;
        if (!$field) {
            return true;
        }

        // Check if parent section is visible
        $sectionId = $field['section_id'] ?? null;
        if ($sectionId && !$this->isSectionVisible($sectionId)) {
            return $this->visibilityCache["field:{$fieldKey}"] = false;
        }

        $conditions = $field['conditions'] ?? [];
        $result = $this->evaluateVisibilityConditions($conditions);

        return $this->visibilityCache["field:{$fieldKey}"] = $result;
    }

    /**
     * Check if a section should be visible
     */
    public function isSectionVisible(string $sectionId): bool
    {
        if (isset($this->visibilityCache["section:{$sectionId}"])) {
            return $this->visibilityCache["section:{$sectionId}"];
        }

        $section = $this->sectionMap[$sectionId] ?? null;
        if (!$section) {
            return true;
        }

        $conditions = $section['conditions'] ?? [];
        $result = $this->evaluateVisibilityConditions($conditions);

        return $this->visibilityCache["section:{$sectionId}"] = $result;
    }

    /**
     * Check if a field should be required (based on conditions)
     */
    public function isFieldRequired(string $fieldKey): bool
    {
        $field = $this->fieldMap[$fieldKey] ?? null;
        if (!$field) {
            return false;
        }

        // Base required from schema
        $baseRequired = $field['required'] ?? false;

        // Check conditional require
        $conditions = $field['conditions'] ?? [];
        foreach ($conditions as $condition) {
            if (($condition['action'] ?? null) !== 'require') {
                continue;
            }

            if ($this->evaluateCondition($condition)) {
                return true;
            }
        }

        return $baseRequired;
    }

    /**
     * Get the next section to show (for branching/skip logic)
     */
    public function getNextSection(string $currentSectionId): ?string
    {
        $section = $this->sectionMap[$currentSectionId] ?? null;
        if (!$section) {
            return null;
        }

        // Check section-level skip conditions
        $conditions = $section['conditions'] ?? [];
        foreach ($conditions as $condition) {
            $action = $condition['action'] ?? null;
            if (!in_array($action, ['skip_to_section', 'skip_to_step'])) {
                continue;
            }

            if ($this->evaluateCondition($condition)) {
                return $condition['targetSection'] ?? null;
            }
        }

        // Check field-level skip conditions in this section
        foreach ($section['fields'] ?? [] as $field) {
            $fieldConditions = $field['conditions'] ?? [];
            foreach ($fieldConditions as $condition) {
                $action = $condition['action'] ?? null;
                if (!in_array($action, ['skip_to_section', 'skip_to_step'])) {
                    continue;
                }

                if ($this->evaluateCondition($condition)) {
                    return $condition['targetSection'] ?? null;
                }
            }
        }

        // Return next sequential section
        $currentIndex = $section['index'] ?? 0;
        $sections = $this->schema['sections'] ?? [];

        for ($i = $currentIndex + 1; $i < count($sections); $i++) {
            $nextSectionId = $sections[$i]['id'] ?? "section_{$i}";
            if ($this->isSectionVisible($nextSectionId)) {
                return $nextSectionId;
            }
        }

        return null; // No more sections
    }

    /**
     * Get all visible fields
     */
    public function getVisibleFields(): array
    {
        $visible = [];
        foreach ($this->fieldMap as $key => $field) {
            if ($this->isFieldVisible($key)) {
                $visible[$key] = $field;
            }
        }
        return $visible;
    }

    /**
     * Get all visible sections
     */
    public function getVisibleSections(): array
    {
        $visible = [];
        foreach ($this->sectionMap as $id => $section) {
            if ($this->isSectionVisible($id)) {
                $visible[$id] = $section;
            }
        }
        return $visible;
    }

    /**
     * Evaluate visibility conditions (show/hide)
     */
    private function evaluateVisibilityConditions(array $conditions): bool
    {
        $hasShowCondition = false;
        $showConditionMet = false;

        foreach ($conditions as $condition) {
            $action = $condition['action'] ?? null;

            if ($action === 'show') {
                $hasShowCondition = true;
                if ($this->evaluateCondition($condition)) {
                    $showConditionMet = true;
                }
            }

            if ($action === 'hide' && $this->evaluateCondition($condition)) {
                return false;
            }
        }

        // If there's a show condition, field is hidden unless condition is met
        if ($hasShowCondition && !$showConditionMet) {
            return false;
        }

        return true;
    }

    /**
     * Evaluate a single condition
     */
    public function evaluateCondition(array $condition): bool
    {
        $targetField = $condition['field'] ?? null;
        if (!$targetField) {
            return false;
        }

        $value = $this->data[$targetField] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $conditionValue = $condition['value'] ?? null;

        return $this->evaluateOperator($operator, $value, $conditionValue);
    }

    /**
     * Evaluate an operator
     */
    private function evaluateOperator(string $operator, mixed $value, mixed $conditionValue): bool
    {
        return match ($operator) {
            'equals' => $this->looseEquals($value, $conditionValue),
            'not_equals' => !$this->looseEquals($value, $conditionValue),
            'contains' => $this->contains($value, $conditionValue),
            'not_contains' => !$this->contains($value, $conditionValue),
            'greater_than' => is_numeric($value) && is_numeric($conditionValue) && (float)$value > (float)$conditionValue,
            'less_than' => is_numeric($value) && is_numeric($conditionValue) && (float)$value < (float)$conditionValue,
            'greater_than_or_equals' => is_numeric($value) && is_numeric($conditionValue) && (float)$value >= (float)$conditionValue,
            'less_than_or_equals' => is_numeric($value) && is_numeric($conditionValue) && (float)$value <= (float)$conditionValue,
            'is_empty' => $this->isEmpty($value),
            'is_not_empty' => !$this->isEmpty($value),
            'in' => is_array($conditionValue) && in_array($value, $conditionValue, false),
            'not_in' => is_array($conditionValue) && !in_array($value, $conditionValue, false),
            'is_checked' => $this->isTruthy($value),
            'is_not_checked' => !$this->isTruthy($value),
            default => false,
        };
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Handle numeric comparison
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a === (float)$b;
        }

        // Handle string comparison (case-insensitive for strings)
        if (is_string($a) && is_string($b)) {
            return strtolower($a) === strtolower($b);
        }

        return $a == $b;
    }

    private function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_array($haystack)) {
            return in_array($needle, $haystack, false);
        }

        if (is_string($haystack) && is_string($needle)) {
            return str_contains(strtolower($haystack), strtolower($needle));
        }

        return false;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        return (bool)$value;
    }

    /**
     * Build internal lookup maps
     */
    private function buildMaps(): void
    {
        $this->fieldMap = [];
        $this->sectionMap = [];

        foreach ($this->schema['sections'] ?? [] as $index => $section) {
            $sectionId = $section['id'] ?? "section_{$index}";
            $this->sectionMap[$sectionId] = array_merge($section, ['index' => $index]);

            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['key'])) {
                    $this->fieldMap[$field['key']] = array_merge($field, ['section_id' => $sectionId]);
                }
            }
        }
    }

    /**
     * Export evaluation state (for debugging/testing)
     */
    public function getState(): array
    {
        return [
            'data' => $this->data,
            'visible_fields' => array_keys($this->getVisibleFields()),
            'visible_sections' => array_keys($this->getVisibleSections()),
        ];
    }
}
