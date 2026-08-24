/**
 * Form Schema Types v1.0
 * 
 * These types are aligned with the backend FormSchemaContract.
 * Any changes must be synchronized with the PHP schema definition.
 */

export const SCHEMA_VERSION = '1.0' as const;

// Field types
export const FIELD_TYPES = [
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
] as const;

export type FieldType = typeof FIELD_TYPES[number];

// Presentational fields (don't collect data)
export const PRESENTATIONAL_FIELDS: FieldType[] = ['heading'];

// Fields that require options
export const FIELDS_WITH_OPTIONS: FieldType[] = ['select', 'radio', 'checkbox_group'];

// Condition operators
export const CONDITION_OPERATORS = [
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
] as const;

export type ConditionOperator = typeof CONDITION_OPERATORS[number];

// Condition actions
export const CONDITION_ACTIONS = ['show', 'hide', 'require', 'disable'] as const;

export type ConditionAction = typeof CONDITION_ACTIONS[number];

// Schema limits
export const SCHEMA_LIMITS = {
    MAX_SCHEMA_SIZE_BYTES: 1048576,
    MAX_FIELD_COUNT: 200,
    MAX_SECTION_COUNT: 50,
    MAX_OPTION_COUNT: 500,
} as const;

// Option for select/radio/checkbox_group
export interface FieldOption {
    value: string | number;
    label: string;
}

// Conditional rule
export interface FieldCondition {
    field: string; // ID of the field to check
    operator: ConditionOperator;
    value?: string | number | boolean | (string | number)[];
    action: ConditionAction;
}

// Base field properties (common to all fields)
export interface BaseField {
    id: string;
    key: string;
    type: FieldType;
    label?: string;
    helpText?: string;
    required?: boolean;
    conditions?: FieldCondition[];
    width?: 'full' | 'half' | 'third' | 'quarter';
    customError?: string;
}

// Text field
export interface TextField extends BaseField {
    type: 'text';
    placeholder?: string;
    defaultValue?: string;
    minLength?: number;
    maxLength?: number;
    pattern?: string;
}

// Textarea field
export interface TextareaField extends BaseField {
    type: 'textarea';
    placeholder?: string;
    defaultValue?: string;
    minLength?: number;
    maxLength?: number;
    rows?: number;
}

// Number field
export interface NumberField extends BaseField {
    type: 'number';
    placeholder?: string;
    defaultValue?: number;
    min?: number;
    max?: number;
    step?: number;
}

// Email field
export interface EmailField extends BaseField {
    type: 'email';
    placeholder?: string;
    defaultValue?: string;
}

// Phone field
export interface PhoneField extends BaseField {
    type: 'phone';
    placeholder?: string;
    defaultValue?: string;
    pattern?: string;
}

// Date field
export interface DateField extends BaseField {
    type: 'date';
    placeholder?: string;
    defaultValue?: string;
    min?: string;
    max?: string;
}

// Select field
export interface SelectField extends BaseField {
    type: 'select';
    options: FieldOption[];
    placeholder?: string;
    defaultValue?: string | number;
    multiple?: boolean;
}

// Radio field
export interface RadioField extends BaseField {
    type: 'radio';
    options: FieldOption[];
    defaultValue?: string | number;
}

// Checkbox group field
export interface CheckboxGroupField extends BaseField {
    type: 'checkbox_group';
    options: FieldOption[];
    defaultValue?: (string | number)[];
    minSelected?: number;
    maxSelected?: number;
}

// Single checkbox field
export interface CheckboxField extends BaseField {
    type: 'checkbox';
    defaultValue?: boolean;
}

// File field
export interface FileField extends BaseField {
    type: 'file';
    accept?: string[];
    maxSize?: number; // in bytes
    multiple?: boolean;
    maxFiles?: number;
}

// Heading field (presentational)
export interface HeadingField extends BaseField {
    type: 'heading';
    level?: 1 | 2 | 3 | 4 | 5 | 6;
    content?: string;
}

// Rating field
export interface RatingField extends BaseField {
    type: 'rating';
    defaultValue?: number;
    min?: number;
    max?: number;
    step?: number;
}

// URL field
export interface UrlField extends BaseField {
    type: 'url';
    placeholder?: string;
    defaultValue?: string;
}

// Union of all field types
export type FormField =
    | TextField
    | TextareaField
    | NumberField
    | EmailField
    | PhoneField
    | DateField
    | SelectField
    | RadioField
    | CheckboxGroupField
    | CheckboxField
    | FileField
    | HeadingField
    | RatingField
    | UrlField;

// Section
export interface FormSection {
    id: string;
    title?: string;
    description?: string;
    fields: FormField[];
}

// Form metadata
export interface FormMetadata {
    title: string;
    description?: string;
}

// Form settings
export interface FormSettings {
    submitButtonText?: string;
    showProgressBar?: boolean;
    allowSaveDraft?: boolean;
}

// Complete form schema
export interface FormSchema {
    schemaVersion: typeof SCHEMA_VERSION;
    metadata: FormMetadata;
    settings?: FormSettings;
    sections: FormSection[];
}

// Schema validation error
export interface SchemaValidationError {
    path: string;
    message: string;
    code: string;
}

// Form status
export type FormStatus = 'draft' | 'published' | 'archived';

// Form version change type
export type VersionChangeType = 'created' | 'updated' | 'published' | 'restored';

// Form version
export interface FormVersion {
    id: number;
    form_id: number;
    version_number: number;
    schema_version: string;
    schema: FormSchema;
    change_type: VersionChangeType;
    is_published: boolean;
    published_at: string | null;
    created_by: number;
    created_at: string;
    updated_at: string;
}

// Form
export interface Form {
    id: number;
    tenant_id: number;
    created_by: number;
    title: string;
    description: string | null;
    slug: string;
    status: FormStatus;
    success_message: string | null;
    settings: Record<string, unknown> | null;
    current_version_id: number | null;
    current_version?: FormVersion;
    created_at: string;
    updated_at: string;
}

// Helper to create empty schema
export function createEmptySchema(): FormSchema {
    return {
        schemaVersion: SCHEMA_VERSION,
        metadata: {
            title: '',
            description: '',
        },
        settings: {
            submitButtonText: 'Submit',
            showProgressBar: false,
            allowSaveDraft: false,
        },
        sections: [],
    };
}

// Helper to create default schema with one section
export function createDefaultSchema(title: string = 'Untitled Form'): FormSchema {
    return {
        schemaVersion: SCHEMA_VERSION,
        metadata: {
            title,
            description: '',
        },
        settings: {
            submitButtonText: 'Submit',
            showProgressBar: false,
            allowSaveDraft: false,
        },
        sections: [
            {
                id: `section_${crypto.randomUUID()}`,
                title: 'Section 1',
                description: '',
                fields: [],
            },
        ],
    };
}

// Helper to generate unique field ID
export function generateFieldId(): string {
    return `field_${crypto.randomUUID()}`;
}

// Helper to generate unique section ID
export function generateSectionId(): string {
    return `section_${crypto.randomUUID()}`;
}

// Type guard for checking if field requires options
export function fieldRequiresOptions(type: FieldType): boolean {
    return FIELDS_WITH_OPTIONS.includes(type);
}

// Type guard for checking if field is presentational
export function isPresentationalField(type: FieldType): boolean {
    return PRESENTATIONAL_FIELDS.includes(type);
}
