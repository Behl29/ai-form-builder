<?php

namespace App\Services\Import;

use App\Services\FormSchema\FormSchemaContract;
use Illuminate\Support\Str;

/**
 * Converts parsed elements into a valid form schema
 */
class ImportSchemaBuilder
{
    private array $usedIds = [];
    private array $usedKeys = [];

    public function build(array $elements, ?string $title = null): array
    {
        $this->usedIds = [];
        $this->usedKeys = [];

        $schema = FormSchemaContract::emptySchema();
        $schema['metadata']['title'] = $title ?? 'Imported Form';

        $sections = $this->groupIntoSections($elements);

        foreach ($sections as $sectionData) {
            $section = $this->buildSection($sectionData);
            if (!empty($section['fields']) || !empty($section['title'])) {
                $schema['sections'][] = $section;
            }
        }

        // Ensure at least one section
        if (empty($schema['sections'])) {
            $schema['sections'][] = [
                'id' => $this->generateUniqueId('section'),
                'title' => 'Section 1',
                'description' => '',
                'fields' => [],
            ];
        }

        return $schema;
    }

    private function groupIntoSections(array $elements): array
    {
        $sections = [];
        $currentSection = [
            'title' => null,
            'elements' => [],
        ];

        foreach ($elements as $element) {
            // Skip unparseable elements
            if (!$element['parseable']) {
                continue;
            }

            // Headings start new sections
            if ($element['type'] === ParsedElement::TYPE_HEADING) {
                // Save current section if it has elements
                if (!empty($currentSection['elements'])) {
                    $sections[] = $currentSection;
                }

                $currentSection = [
                    'title' => $element['label'] ?? $element['detected_section'],
                    'elements' => [],
                ];
                continue;
            }

            // Add element to current section
            $currentSection['elements'][] = $element;
        }

        // Don't forget the last section
        if (!empty($currentSection['elements']) || $currentSection['title'] !== null) {
            $sections[] = $currentSection;
        }

        // If no sections were created, create one with all elements
        if (empty($sections)) {
            $sections[] = [
                'title' => 'Section 1',
                'elements' => array_filter($elements, fn($e) => $e['parseable']),
            ];
        }

        return $sections;
    }

    private function buildSection(array $sectionData): array
    {
        $section = [
            'id' => $this->generateUniqueId('section'),
            'title' => $sectionData['title'] ?? 'Section',
            'description' => '',
            'fields' => [],
        ];

        foreach ($sectionData['elements'] as $element) {
            $field = $this->buildField($element);
            if ($field !== null) {
                $section['fields'][] = $field;
            }
        }

        return $section;
    }

    private function buildField(array $element): ?array
    {
        $fieldType = $element['detected_field_type'] ?? 'text';

        // Skip if not a valid field type
        if (!in_array($fieldType, FormSchemaContract::FIELD_TYPES)) {
            $fieldType = 'text';
        }

        $field = [
            'id' => $this->generateUniqueId('field'),
            'key' => $this->generateUniqueKey($element['key'] ?? $element['label'] ?? 'field'),
            'type' => $fieldType,
            'label' => $element['label'] ?? 'Field',
        ];

        // Add options for fields that need them
        if (in_array($fieldType, FormSchemaContract::FIELDS_WITH_OPTIONS)) {
            $field['options'] = $this->buildOptions($element['options'] ?? []);

            // Ensure at least one option
            if (empty($field['options'])) {
                $field['options'] = [
                    ['value' => 'option_1', 'label' => 'Option 1'],
                ];
            }
        }

        // Add validations
        $validations = $element['validations'] ?? [];
        if (!empty($validations['required'])) {
            $field['required'] = true;
        }
        if (isset($validations['min'])) {
            $field['min'] = $validations['min'];
        }
        if (isset($validations['max'])) {
            $field['max'] = $validations['max'];
        }
        if (isset($validations['minLength'])) {
            $field['minLength'] = $validations['minLength'];
        }
        if (isset($validations['maxLength'])) {
            $field['maxLength'] = $validations['maxLength'];
        }

        // Add metadata fields
        $metadata = $element['metadata'] ?? [];
        if (!empty($metadata['placeholder'])) {
            $field['placeholder'] = $metadata['placeholder'];
        }
        if (!empty($metadata['help_text'])) {
            $field['helpText'] = $metadata['help_text'];
        }

        return $field;
    }

    private function buildOptions(array $options): array
    {
        $built = [];
        $usedValues = [];

        foreach ($options as $option) {
            $value = $option['value'] ?? $this->generateOptionValue($option['label'] ?? 'option');
            $label = $option['label'] ?? $value;

            // Ensure unique values
            $originalValue = $value;
            $counter = 1;
            while (in_array($value, $usedValues)) {
                $value = $originalValue . '_' . $counter++;
            }
            $usedValues[] = $value;

            $built[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $built;
    }

    private function generateUniqueId(string $prefix): string
    {
        do {
            $id = $prefix . '_' . Str::random(8);
        } while (in_array($id, $this->usedIds));

        $this->usedIds[] = $id;
        return $id;
    }

    private function generateUniqueKey(string $base): string
    {
        $key = strtolower($base);
        $key = preg_replace('/[^a-z0-9\s]/', '', $key);
        $key = preg_replace('/\s+/', '_', trim($key));
        $key = substr($key, 0, 50);

        if (empty($key) || !preg_match('/^[a-z]/', $key)) {
            $key = 'field_' . substr(md5($base), 0, 8);
        }

        $originalKey = $key;
        $counter = 1;
        while (in_array($key, $this->usedKeys)) {
            $key = $originalKey . '_' . $counter++;
        }

        $this->usedKeys[] = $key;
        return $key;
    }

    private function generateOptionValue(string $label): string
    {
        $value = strtolower($label);
        $value = preg_replace('/[^a-z0-9\s]/', '', $value);
        $value = preg_replace('/\s+/', '_', trim($value));
        return substr($value, 0, 50) ?: 'option_' . substr(md5($label), 0, 6);
    }
}
