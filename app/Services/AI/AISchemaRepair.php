<?php

namespace App\Services\AI;

use App\Services\FormSchema\FormSchemaContract;
use App\Services\FormSchema\FormSchemaValidator;
use Illuminate\Support\Str;

/**
 * Repairs and normalizes AI-generated schemas
 */
class AISchemaRepair
{
    private array $repairLog = [];

    public function __construct(
        private FormSchemaValidator $validator
    ) {}

    /**
     * Attempt to repair a schema
     * 
     * @return array{success: bool, schema: ?array, errors: array, repairs: array}
     */
    public function repair(array $schema): array
    {
        $this->repairLog = [];

        // Step 1: Normalize structure
        $schema = $this->normalizeStructure($schema);

        // Step 2: Fix schema version
        $schema = $this->fixSchemaVersion($schema);

        // Step 3: Fix metadata
        $schema = $this->fixMetadata($schema);

        // Step 4: Fix settings
        $schema = $this->fixSettings($schema);

        // Step 5: Fix sections
        $schema = $this->fixSections($schema);

        // Step 6: Fix fields
        $schema = $this->fixFields($schema);

        // Step 7: Remove unsupported properties
        $schema = $this->removeUnsupportedProperties($schema);

        // Step 8: Validate
        $errors = $this->validator->validateAndGetErrors($schema);

        return [
            'success' => empty($errors),
            'schema' => empty($errors) ? $schema : null,
            'errors' => $errors,
            'repairs' => $this->repairLog,
        ];
    }

    /**
     * Check if schema can potentially be repaired
     */
    public function canRepair(array $schema): bool
    {
        // Must have some structure
        if (empty($schema)) {
            return false;
        }

        // Must have sections or fields somewhere
        return isset($schema['sections']) || isset($schema['fields']);
    }

    private function normalizeStructure(array $schema): array
    {
        // If schema has fields at root level, wrap in section
        if (isset($schema['fields']) && !isset($schema['sections'])) {
            $this->log('Wrapped root fields in section');
            $schema['sections'] = [
                [
                    'id' => 'section_' . Str::random(8),
                    'title' => 'Section 1',
                    'fields' => $schema['fields'],
                ],
            ];
            unset($schema['fields']);
        }

        return $schema;
    }

    private function fixSchemaVersion(array $schema): array
    {
        if (!isset($schema['schemaVersion']) || $schema['schemaVersion'] !== FormSchemaContract::SCHEMA_VERSION) {
            $this->log('Fixed schema version');
            $schema['schemaVersion'] = FormSchemaContract::SCHEMA_VERSION;
        }

        return $schema;
    }

    private function fixMetadata(array $schema): array
    {
        if (!isset($schema['metadata'])) {
            $this->log('Added missing metadata');
            $schema['metadata'] = [];
        }

        if (!isset($schema['metadata']['title']) || empty($schema['metadata']['title'])) {
            $this->log('Added default title');
            $schema['metadata']['title'] = 'Untitled Form';
        }

        if (!isset($schema['metadata']['description'])) {
            $schema['metadata']['description'] = '';
        }

        return $schema;
    }

    private function fixSettings(array $schema): array
    {
        if (!isset($schema['settings'])) {
            $this->log('Added missing settings');
            $schema['settings'] = [];
        }

        $defaults = [
            'submitButtonText' => 'Submit',
            'showProgressBar' => false,
            'allowSaveDraft' => false,
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($schema['settings'][$key])) {
                $schema['settings'][$key] = $value;
            }
        }

        return $schema;
    }

    private function fixSections(array $schema): array
    {
        if (!isset($schema['sections']) || !is_array($schema['sections'])) {
            $this->log('Added empty sections array');
            $schema['sections'] = [];
            return $schema;
        }

        $usedIds = [];

        foreach ($schema['sections'] as $index => &$section) {
            // Ensure section is array
            if (!is_array($section)) {
                $this->log("Removed invalid section at index {$index}");
                unset($schema['sections'][$index]);
                continue;
            }

            // Fix missing ID
            if (!isset($section['id']) || empty($section['id'])) {
                $section['id'] = 'section_' . Str::random(8);
                $this->log("Generated ID for section at index {$index}");
            }

            // Fix duplicate IDs
            if (in_array($section['id'], $usedIds)) {
                $section['id'] = 'section_' . Str::random(8);
                $this->log("Fixed duplicate section ID at index {$index}");
            }
            $usedIds[] = $section['id'];

            // Fix missing title
            if (!isset($section['title'])) {
                $section['title'] = 'Section ' . ($index + 1);
            }

            // Ensure fields array exists
            if (!isset($section['fields']) || !is_array($section['fields'])) {
                $section['fields'] = [];
            }
        }

        // Re-index array
        $schema['sections'] = array_values($schema['sections']);

        return $schema;
    }

    private function fixFields(array $schema): array
    {
        $usedIds = [];
        $usedKeys = [];

        foreach ($schema['sections'] as $sectionIndex => &$section) {
            foreach ($section['fields'] as $fieldIndex => &$field) {
                // Ensure field is array
                if (!is_array($field)) {
                    $this->log("Removed invalid field at section {$sectionIndex}, field {$fieldIndex}");
                    unset($section['fields'][$fieldIndex]);
                    continue;
                }

                // Fix unsupported field type
                if (!isset($field['type']) || !in_array($field['type'], FormSchemaContract::FIELD_TYPES)) {
                    $originalType = $field['type'] ?? 'unknown';
                    $field['type'] = $this->mapFieldType($originalType);
                    $this->log("Mapped unsupported field type '{$originalType}' to '{$field['type']}'");
                }

                // Fix missing ID
                if (!isset($field['id']) || empty($field['id'])) {
                    $field['id'] = 'field_' . Str::random(8);
                    $this->log("Generated ID for field at section {$sectionIndex}, field {$fieldIndex}");
                }

                // Fix duplicate IDs
                if (in_array($field['id'], $usedIds)) {
                    $field['id'] = 'field_' . Str::random(8);
                    $this->log("Fixed duplicate field ID");
                }
                $usedIds[] = $field['id'];

                // Fix missing key
                if (!isset($field['key']) || empty($field['key'])) {
                    $field['key'] = $this->generateKey($field['label'] ?? $field['id']);
                    $this->log("Generated key for field");
                }

                // Fix invalid key format
                $field['key'] = $this->sanitizeKey($field['key']);

                // Fix duplicate keys
                if (in_array($field['key'], $usedKeys)) {
                    $field['key'] = $field['key'] . '_' . strtolower(Str::random(4));
                    $field['key'] = $this->sanitizeKey($field['key']); // Re-sanitize
                    $this->log("Fixed duplicate field key");
                }
                $usedKeys[] = $field['key'];

                // Fix missing label for non-presentational fields
                if (!in_array($field['type'], FormSchemaContract::PRESENTATIONAL_FIELDS)) {
                    if (!isset($field['label']) || empty($field['label'])) {
                        $field['label'] = ucwords(str_replace('_', ' ', $field['key']));
                        $this->log("Generated label from key");
                    }
                }

                // Fix options for fields that require them
                if (in_array($field['type'], FormSchemaContract::FIELDS_WITH_OPTIONS)) {
                    $field = $this->fixFieldOptions($field);
                }

                // Fix file field properties
                if ($field['type'] === 'file') {
                    $field = $this->fixFileField($field);
                }

                // Fix number constraints
                if ($field['type'] === 'number' || $field['type'] === 'rating') {
                    $field = $this->fixNumberConstraints($field);
                }

                // Fix text constraints
                if (in_array($field['type'], ['text', 'textarea'])) {
                    $field = $this->fixTextConstraints($field);
                }
            }

            // Re-index fields array
            $section['fields'] = array_values($section['fields']);
        }

        return $schema;
    }

    private function fixFieldOptions(array $field): array
    {
        if (!isset($field['options']) || !is_array($field['options']) || empty($field['options'])) {
            $this->log("Added default options for {$field['type']} field");
            $field['options'] = [
                ['value' => 'option_1', 'label' => 'Option 1'],
                ['value' => 'option_2', 'label' => 'Option 2'],
            ];
            return $field;
        }

        $usedValues = [];
        foreach ($field['options'] as $index => &$option) {
            if (!is_array($option)) {
                $option = ['value' => "option_{$index}", 'label' => (string) $option];
            }

            if (!isset($option['value'])) {
                $option['value'] = 'option_' . ($index + 1);
            }

            if (!isset($option['label'])) {
                $option['label'] = ucwords(str_replace('_', ' ', $option['value']));
            }

            // Fix duplicate values
            if (in_array($option['value'], $usedValues)) {
                $option['value'] = $option['value'] . '_' . ($index + 1);
            }
            $usedValues[] = $option['value'];
        }

        return $field;
    }

    private function fixFileField(array $field): array
    {
        // Ensure accept is array
        if (isset($field['accept']) && !is_array($field['accept'])) {
            $field['accept'] = [$field['accept']];
        }

        // Ensure maxSize is reasonable
        if (isset($field['maxSize'])) {
            $maxSize = (int) $field['maxSize'];
            // Cap at 50MB
            if ($maxSize > 52428800) {
                $field['maxSize'] = 52428800;
                $this->log("Capped file maxSize to 50MB");
            }
            // Minimum 1KB
            if ($maxSize < 1024) {
                $field['maxSize'] = 1024;
            }
        }

        return $field;
    }

    private function fixNumberConstraints(array $field): array
    {
        if (isset($field['min']) && isset($field['max'])) {
            if ($field['min'] > $field['max']) {
                $this->log("Swapped min/max for number field");
                [$field['min'], $field['max']] = [$field['max'], $field['min']];
            }
        }

        return $field;
    }

    private function fixTextConstraints(array $field): array
    {
        if (isset($field['minLength']) && isset($field['maxLength'])) {
            if ($field['minLength'] > $field['maxLength']) {
                $this->log("Swapped minLength/maxLength for text field");
                [$field['minLength'], $field['maxLength']] = [$field['maxLength'], $field['minLength']];
            }
        }

        return $field;
    }

    private function removeUnsupportedProperties(array $schema): array
    {
        foreach ($schema['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                $type = $field['type'];
                $allowedProps = array_merge(
                    FormSchemaContract::COMMON_FIELD_PROPERTIES,
                    FormSchemaContract::FIELD_PROPERTIES[$type] ?? []
                );

                foreach (array_keys($field) as $prop) {
                    if (!in_array($prop, $allowedProps)) {
                        $this->log("Removed unsupported property '{$prop}' from {$type} field");
                        unset($field[$prop]);
                    }
                }
            }
        }

        return $schema;
    }

    private function mapFieldType(string $type): string
    {
        $mappings = [
            'string' => 'text',
            'input' => 'text',
            'textfield' => 'text',
            'textbox' => 'text',
            'multiline' => 'textarea',
            'longtext' => 'textarea',
            'integer' => 'number',
            'float' => 'number',
            'decimal' => 'number',
            'dropdown' => 'select',
            'choice' => 'select',
            'options' => 'select',
            'radiobutton' => 'radio',
            'checkboxes' => 'checkbox_group',
            'multi_checkbox' => 'checkbox_group',
            'bool' => 'checkbox',
            'boolean' => 'checkbox',
            'toggle' => 'checkbox',
            'attachment' => 'file',
            'document' => 'file',
            'image' => 'file',
            'title' => 'heading',
            'header' => 'heading',
            'stars' => 'rating',
            'link' => 'url',
            'website' => 'url',
        ];

        $normalized = strtolower(str_replace(['-', ' '], '_', $type));

        return $mappings[$normalized] ?? 'text';
    }

    private function generateKey(string $source): string
    {
        $key = Str::snake(Str::ascii($source));
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        $key = preg_replace('/_{2,}/', '_', $key);
        $key = trim($key, '_');

        if (empty($key) || is_numeric($key[0])) {
            $key = 'field_' . ($key ?: Str::random(6));
        }

        return substr($key, 0, 50);
    }

    private function sanitizeKey(string $key): string
    {
        $key = Str::snake(Str::ascii($key));
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        $key = preg_replace('/_{2,}/', '_', $key);
        $key = trim($key, '_');

        if (empty($key) || is_numeric($key[0])) {
            $key = 'field_' . ($key ?: Str::random(6));
        }

        return substr($key, 0, 50);
    }

    private function log(string $message): void
    {
        $this->repairLog[] = $message;
    }

    public function getRepairLog(): array
    {
        return $this->repairLog;
    }
}
