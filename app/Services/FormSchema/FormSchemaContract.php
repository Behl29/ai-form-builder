<?php

namespace App\Services\FormSchema;

/**
 * Form Schema Contract v1.0
 *
 * This class defines the canonical form schema structure.
 * All field types, validation rules, and schema constraints are defined here.
 */
final class FormSchemaContract
{
    public const SCHEMA_VERSION = '1.0';

    // Schema limits
    public const MAX_SCHEMA_SIZE_BYTES = 1048576; // 1MB
    public const MAX_FIELD_COUNT = 200;
    public const MAX_SECTION_COUNT = 50;
    public const MAX_OPTION_COUNT = 500;

    // Supported field types
    public const FIELD_TYPES = [
        'text',
        'textarea',
        'number',
        'email',
        'phone',
        'date',
        'select',
        'radio',
        'checkbox_group',
        'checkbox',
        'file',
        'heading',
        'rating',
        'url',
    ];

    // Fields that don't collect data (presentational only)
    public const PRESENTATIONAL_FIELDS = ['heading'];

    // Fields that require options
    public const FIELDS_WITH_OPTIONS = ['select', 'radio', 'checkbox_group'];

    // Condition operators
    public const CONDITION_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'greater_than',
        'less_than',
        'is_empty',
        'is_not_empty',
        'in',
        'not_in',
    ];

    // Condition actions
    public const CONDITION_ACTIONS = ['show', 'hide', 'require', 'disable'];

    // Field properties by type
    public const FIELD_PROPERTIES = [
        'text' => ['minLength', 'maxLength', 'pattern', 'placeholder', 'defaultValue'],
        'textarea' => ['minLength', 'maxLength', 'rows', 'placeholder', 'defaultValue'],
        'number' => ['min', 'max', 'step', 'placeholder', 'defaultValue'],
        'email' => ['placeholder', 'defaultValue'],
        'phone' => ['placeholder', 'defaultValue', 'pattern'],
        'date' => ['min', 'max', 'placeholder', 'defaultValue'],
        'select' => ['options', 'placeholder', 'defaultValue', 'multiple'],
        'radio' => ['options', 'defaultValue'],
        'checkbox_group' => ['options', 'minSelected', 'maxSelected', 'defaultValue'],
        'checkbox' => ['defaultValue'],
        'file' => ['accept', 'maxSize', 'multiple', 'maxFiles'],
        'heading' => ['level', 'content'],
        'rating' => ['min', 'max', 'step', 'defaultValue'],
        'url' => ['placeholder', 'defaultValue'],
    ];

    // Common field properties (all fields can have these)
    public const COMMON_FIELD_PROPERTIES = [
        'id',
        'key',
        'type',
        'label',
        'helpText',
        'required',
        'conditions',
        'width',
        'customError',
    ];

    /**
     * Get empty schema template
     */
    public static function emptySchema(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'metadata' => [
                'title' => '',
                'description' => '',
            ],
            'settings' => [
                'submitButtonText' => 'Submit',
                'showProgressBar' => false,
                'allowSaveDraft' => false,
            ],
            'sections' => [],
        ];
    }

    /**
     * Get schema template with one section
     */
    public static function defaultSchema(string $title = 'Untitled Form'): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'metadata' => [
                'title' => $title,
                'description' => '',
            ],
            'settings' => [
                'submitButtonText' => 'Submit',
                'showProgressBar' => false,
                'allowSaveDraft' => false,
            ],
            'sections' => [
                [
                    'id' => 'section_' . uniqid(),
                    'title' => 'Section 1',
                    'description' => '',
                    'fields' => [],
                ],
            ],
        ];
    }
}
