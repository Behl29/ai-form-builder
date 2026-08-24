<?php

namespace Tests\Unit\Services;

use App\Exceptions\SchemaValidationException;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\FormSchema\FormSchemaValidator;
use PHPUnit\Framework\TestCase;

class FormSchemaValidatorTest extends TestCase
{
    private FormSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FormSchemaValidator();
    }

    private function validSchema(): array
    {
        return [
            'schemaVersion' => '1.0',
            'metadata' => [
                'title' => 'Test Form',
                'description' => 'A test form',
            ],
            'settings' => [
                'submitButtonText' => 'Submit',
            ],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section 1',
                    'fields' => [
                        [
                            'id' => 'field_1',
                            'key' => 'name',
                            'type' => 'text',
                            'label' => 'Name',
                            'required' => true,
                        ],
                        [
                            'id' => 'field_2',
                            'key' => 'email',
                            'type' => 'email',
                            'label' => 'Email',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_valid_schema_passes_validation(): void
    {
        $result = $this->validator->validate($this->validSchema());
        $this->assertTrue($result);
    }

    public function test_missing_schema_version_fails(): void
    {
        $schema = $this->validSchema();
        unset($schema['schemaVersion']);

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_unsupported_schema_version_fails(): void
    {
        $schema = $this->validSchema();
        $schema['schemaVersion'] = '99.0';

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_missing_metadata_fails(): void
    {
        $schema = $this->validSchema();
        unset($schema['metadata']);

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_missing_metadata_title_fails(): void
    {
        $schema = $this->validSchema();
        unset($schema['metadata']['title']);

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_missing_sections_fails(): void
    {
        $schema = $this->validSchema();
        unset($schema['sections']);

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_duplicate_field_ids_fail(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['id'] = 'field_1'; // Same as first field

        $this->expectException(SchemaValidationException::class);

        try {
            $this->validator->validate($schema);
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getErrors()[0]['message']));
            throw $e;
        }
    }

    public function test_duplicate_field_keys_fail(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['key'] = 'name'; // Same as first field

        $this->expectException(SchemaValidationException::class);

        try {
            $this->validator->validate($schema);
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->getErrors()[0]['message']));
            throw $e;
        }
    }

    public function test_unsupported_field_type_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][0]['type'] = 'unsupported_type';

        $this->expectException(SchemaValidationException::class);

        try {
            $this->validator->validate($schema);
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('unsupported', strtolower($e->getErrors()[0]['message']));
            throw $e;
        }
    }

    public function test_invalid_field_key_format_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][0]['key'] = 'Invalid-Key'; // Must be lowercase with underscores

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_field_key_starting_with_number_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][0]['key'] = '1name';

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_select_field_without_options_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'country',
            'type' => 'select',
            'label' => 'Country',
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_select_field_with_empty_options_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'country',
            'type' => 'select',
            'label' => 'Country',
            'options' => [],
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_select_field_with_valid_options_passes(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'country',
            'type' => 'select',
            'label' => 'Country',
            'options' => [
                ['value' => 'us', 'label' => 'United States'],
                ['value' => 'uk', 'label' => 'United Kingdom'],
            ],
        ];

        $result = $this->validator->validate($schema);
        $this->assertTrue($result);
    }

    public function test_duplicate_option_values_fail(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'country',
            'type' => 'select',
            'label' => 'Country',
            'options' => [
                ['value' => 'us', 'label' => 'United States'],
                ['value' => 'us', 'label' => 'USA'], // Duplicate value
            ],
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_invalid_condition_operator_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['conditions'] = [
            [
                'field' => 'field_1',
                'operator' => 'invalid_operator',
                'value' => 'test',
                'action' => 'show',
            ],
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_invalid_condition_action_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['conditions'] = [
            [
                'field' => 'field_1',
                'operator' => 'equals',
                'value' => 'test',
                'action' => 'invalid_action',
            ],
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_self_referencing_condition_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][0]['conditions'] = [
            [
                'field' => 'field_1', // References itself
                'operator' => 'equals',
                'value' => 'test',
                'action' => 'show',
            ],
        ];

        $this->expectException(SchemaValidationException::class);

        try {
            $this->validator->validate($schema);
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('itself', strtolower($e->getErrors()[0]['message']));
            throw $e;
        }
    }

    public function test_valid_condition_passes(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['conditions'] = [
            [
                'field' => 'field_1',
                'operator' => 'equals',
                'value' => 'test',
                'action' => 'show',
            ],
        ];

        $result = $this->validator->validate($schema);
        $this->assertTrue($result);
    }

    public function test_condition_reference_validation(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['conditions'] = [
            [
                'field' => 'nonexistent_field',
                'operator' => 'equals',
                'value' => 'test',
                'action' => 'show',
            ],
        ];

        // First validation passes (structure is valid)
        $this->validator->validate($schema);

        // Reference validation should fail
        $this->expectException(SchemaValidationException::class);
        $this->validator->validateConditionReferences($schema);
    }

    public function test_exceeds_max_field_count_fails(): void
    {
        $schema = $this->validSchema();
        $fields = [];

        for ($i = 0; $i < FormSchemaContract::MAX_FIELD_COUNT + 1; $i++) {
            $fields[] = [
                'id' => "field_{$i}",
                'key' => "field_{$i}",
                'type' => 'text',
                'label' => "Field {$i}",
            ];
        }

        $schema['sections'][0]['fields'] = $fields;

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_exceeds_max_section_count_fails(): void
    {
        $schema = $this->validSchema();
        $sections = [];

        for ($i = 0; $i < FormSchemaContract::MAX_SECTION_COUNT + 1; $i++) {
            $sections[] = [
                'id' => "section_{$i}",
                'title' => "Section {$i}",
                'fields' => [],
            ];
        }

        $schema['sections'] = $sections;

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_number_field_min_greater_than_max_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'age',
            'type' => 'number',
            'label' => 'Age',
            'min' => 100,
            'max' => 10,
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_text_field_minlength_greater_than_maxlength_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][0]['minLength'] = 100;
        $schema['sections'][0]['fields'][0]['maxLength'] = 10;

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_heading_field_without_label_passes(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'heading_1',
            'type' => 'heading',
            'level' => 2,
            'content' => 'Section Header',
        ];

        $result = $this->validator->validate($schema);
        $this->assertTrue($result);
    }

    public function test_invalid_heading_level_fails(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_3',
            'key' => 'heading_1',
            'type' => 'heading',
            'level' => 7, // Invalid: must be 1-6
        ];

        $this->expectException(SchemaValidationException::class);
        $this->validator->validate($schema);
    }

    public function test_error_paths_are_correct(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][1]['type'] = 'invalid';

        try {
            $this->validator->validate($schema);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $errors = $e->getErrors();
            $this->assertStringContainsString('sections[0].fields[1].type', $errors[0]['path']);
        }
    }

    public function test_all_supported_field_types_pass(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'] = [];

        $fieldIndex = 0;
        foreach (FormSchemaContract::FIELD_TYPES as $type) {
            $field = [
                'id' => "field_{$fieldIndex}",
                'key' => "field_{$fieldIndex}",
                'type' => $type,
            ];

            // Add label for non-presentational fields
            if (!in_array($type, FormSchemaContract::PRESENTATIONAL_FIELDS)) {
                $field['label'] = ucfirst($type) . ' Field';
            }

            // Add options for fields that require them
            if (in_array($type, FormSchemaContract::FIELDS_WITH_OPTIONS)) {
                $field['options'] = [
                    ['value' => 'opt1', 'label' => 'Option 1'],
                    ['value' => 'opt2', 'label' => 'Option 2'],
                ];
            }

            $schema['sections'][0]['fields'][] = $field;
            $fieldIndex++;
        }

        $result = $this->validator->validate($schema);
        $this->assertTrue($result);
    }
}
