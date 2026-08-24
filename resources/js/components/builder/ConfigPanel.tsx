import { clsx } from 'clsx';
import { Plus, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FieldOption, FieldType, FormField, FormSection } from '../../types/form-schema';
import { FIELDS_WITH_OPTIONS, isPresentationalField } from '../../types/form-schema';
import { Button } from '../ui';
import { useBuilder } from './BuilderContext';

export function ConfigPanel() {
    const { state, getSelectedField, getSelectedSection, selectField, selectSection } = useBuilder();
    const selectedField = getSelectedField();
    const selectedSection = getSelectedSection();

    if (!selectedField && !selectedSection) {
        return (
            <div className="w-80 bg-white border-l border-gray-200 flex flex-col h-full">
                <div className="p-4 border-b border-gray-200">
                    <h2 className="font-semibold text-gray-900">Properties</h2>
                </div>
                <div className="flex-1 flex items-center justify-center p-6 text-center">
                    <p className="text-gray-500 text-sm">
                        Select a field or section to configure its properties
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="w-80 bg-white border-l border-gray-200 flex flex-col h-full overflow-hidden">
            <div className="p-4 border-b border-gray-200 flex items-center justify-between">
                <h2 className="font-semibold text-gray-900">
                    {selectedField ? 'Field Properties' : 'Section Properties'}
                </h2>
                <button
                    onClick={() => {
                        selectField(null);
                        selectSection(null);
                    }}
                    className="p-1 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
                >
                    <X className="w-4 h-4" />
                </button>
            </div>
            <div className="flex-1 overflow-y-auto p-4">
                {selectedField ? (
                    <FieldConfig field={selectedField} />
                ) : selectedSection ? (
                    <SectionConfig section={selectedSection} />
                ) : null}
            </div>
        </div>
    );
}

interface FieldConfigProps {
    field: FormField;
}

function FieldConfig({ field }: FieldConfigProps) {
    const { updateField, isKeyUnique } = useBuilder();
    const [keyError, setKeyError] = useState<string | null>(null);

    const handleChange = (key: string, value: unknown) => {
        if (key === 'key') {
            const newKey = String(value);
            if (!isKeyUnique(newKey, field.id)) {
                setKeyError('This key is already in use');
                return;
            }
            if (!/^[a-z][a-z0-9_]*$/.test(newKey)) {
                setKeyError('Key must start with a letter and contain only lowercase letters, numbers, and underscores');
                return;
            }
            setKeyError(null);
        }
        updateField(field.id, { [key]: value } as Partial<FormField>);
    };

    return (
        <div className="space-y-6">
            {/* Field Type Badge */}
            <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-gray-500 uppercase">Type:</span>
                <span className="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs font-medium rounded">
                    {field.type}
                </span>
            </div>

            {/* Common Properties */}
            {!isPresentationalField(field.type) && (
                <>
                    <ConfigInput
                        label="Label"
                        value={field.label ?? ''}
                        onChange={(v) => handleChange('label', v)}
                        placeholder="Field label"
                    />

                    <ConfigInput
                        label="Field Key"
                        value={field.key}
                        onChange={(v) => handleChange('key', v)}
                        placeholder="field_key"
                        error={keyError ?? undefined}
                        helpText="Unique identifier for this field"
                    />

                    <ConfigTextarea
                        label="Help Text"
                        value={field.helpText ?? ''}
                        onChange={(v) => handleChange('helpText', v)}
                        placeholder="Additional instructions for the user"
                    />

                    <ConfigCheckbox
                        label="Required"
                        checked={field.required ?? false}
                        onChange={(v) => handleChange('required', v)}
                    />
                </>
            )}

            {/* Type-specific Properties */}
            <TypeSpecificConfig field={field} onChange={handleChange} />

            {/* Layout */}
            {!isPresentationalField(field.type) && (
                <ConfigSelect
                    label="Width"
                    value={field.width ?? 'full'}
                    onChange={(v) => handleChange('width', v)}
                    options={[
                        { value: 'full', label: 'Full Width' },
                        { value: 'half', label: 'Half Width' },
                        { value: 'third', label: 'One Third' },
                        { value: 'quarter', label: 'One Quarter' },
                    ]}
                />
            )}

            {/* Custom Error Message */}
            {!isPresentationalField(field.type) && (
                <ConfigInput
                    label="Custom Error Message"
                    value={field.customError ?? ''}
                    onChange={(v) => handleChange('customError', v)}
                    placeholder="Custom validation error"
                />
            )}

            {/* Conditions Placeholder */}
            <div className="pt-4 border-t border-gray-200">
                <h3 className="text-sm font-medium text-gray-700 mb-2">Conditional Logic</h3>
                <p className="text-xs text-gray-500">
                    Configure when this field should be shown, hidden, or required based on other field values.
                </p>
                <Button variant="secondary" size="sm" className="mt-2" disabled>
                    <Plus className="w-3 h-3 mr-1" />
                    Add Condition
                </Button>
            </div>
        </div>
    );
}

interface TypeSpecificConfigProps {
    field: FormField;
    onChange: (key: string, value: unknown) => void;
}

function TypeSpecificConfig({ field, onChange }: TypeSpecificConfigProps) {
    switch (field.type) {
        case 'text':
        case 'email':
        case 'phone':
        case 'url':
            return (
                <>
                    <ConfigInput
                        label="Placeholder"
                        value={field.placeholder ?? ''}
                        onChange={(v) => onChange('placeholder', v)}
                    />
                    <ConfigInput
                        label="Default Value"
                        value={field.defaultValue ?? ''}
                        onChange={(v) => onChange('defaultValue', v)}
                    />
                    {field.type === 'text' && (
                        <>
                            <div className="grid grid-cols-2 gap-3">
                                <ConfigNumber
                                    label="Min Length"
                                    value={field.minLength}
                                    onChange={(v) => onChange('minLength', v)}
                                    min={0}
                                />
                                <ConfigNumber
                                    label="Max Length"
                                    value={field.maxLength}
                                    onChange={(v) => onChange('maxLength', v)}
                                    min={0}
                                />
                            </div>
                            <ConfigInput
                                label="Pattern (Regex)"
                                value={field.pattern ?? ''}
                                onChange={(v) => onChange('pattern', v)}
                                placeholder="^[A-Za-z]+$"
                            />
                        </>
                    )}
                    {field.type === 'phone' && (
                        <ConfigInput
                            label="Pattern (Regex)"
                            value={field.pattern ?? ''}
                            onChange={(v) => onChange('pattern', v)}
                            placeholder="^\+?[0-9\s-]+$"
                        />
                    )}
                </>
            );

        case 'textarea':
            return (
                <>
                    <ConfigInput
                        label="Placeholder"
                        value={field.placeholder ?? ''}
                        onChange={(v) => onChange('placeholder', v)}
                    />
                    <ConfigTextarea
                        label="Default Value"
                        value={field.defaultValue ?? ''}
                        onChange={(v) => onChange('defaultValue', v)}
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <ConfigNumber
                            label="Min Length"
                            value={field.minLength}
                            onChange={(v) => onChange('minLength', v)}
                            min={0}
                        />
                        <ConfigNumber
                            label="Max Length"
                            value={field.maxLength}
                            onChange={(v) => onChange('maxLength', v)}
                            min={0}
                        />
                    </div>
                    <ConfigNumber
                        label="Rows"
                        value={field.rows ?? 3}
                        onChange={(v) => onChange('rows', v)}
                        min={1}
                        max={20}
                    />
                </>
            );

        case 'number':
            return (
                <>
                    <ConfigInput
                        label="Placeholder"
                        value={field.placeholder ?? ''}
                        onChange={(v) => onChange('placeholder', v)}
                    />
                    <ConfigNumber
                        label="Default Value"
                        value={field.defaultValue}
                        onChange={(v) => onChange('defaultValue', v)}
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <ConfigNumber
                            label="Min"
                            value={field.min}
                            onChange={(v) => onChange('min', v)}
                        />
                        <ConfigNumber
                            label="Max"
                            value={field.max}
                            onChange={(v) => onChange('max', v)}
                        />
                    </div>
                    <ConfigNumber
                        label="Step"
                        value={field.step ?? 1}
                        onChange={(v) => onChange('step', v)}
                        min={0}
                        step={0.1}
                    />
                </>
            );

        case 'date':
            return (
                <>
                    <ConfigInput
                        label="Default Value"
                        value={field.defaultValue ?? ''}
                        onChange={(v) => onChange('defaultValue', v)}
                        type="date"
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <ConfigInput
                            label="Min Date"
                            value={field.min ?? ''}
                            onChange={(v) => onChange('min', v)}
                            type="date"
                        />
                        <ConfigInput
                            label="Max Date"
                            value={field.max ?? ''}
                            onChange={(v) => onChange('max', v)}
                            type="date"
                        />
                    </div>
                </>
            );

        case 'select':
        case 'radio':
        case 'checkbox_group':
            return (
                <>
                    <OptionsEditor
                        options={field.options ?? []}
                        onChange={(options) => onChange('options', options)}
                    />
                    {field.type === 'select' && (
                        <>
                            <ConfigInput
                                label="Placeholder"
                                value={field.placeholder ?? ''}
                                onChange={(v) => onChange('placeholder', v)}
                                placeholder="Select an option"
                            />
                            <ConfigCheckbox
                                label="Allow Multiple"
                                checked={field.multiple ?? false}
                                onChange={(v) => onChange('multiple', v)}
                            />
                        </>
                    )}
                    {field.type === 'checkbox_group' && (
                        <div className="grid grid-cols-2 gap-3">
                            <ConfigNumber
                                label="Min Selected"
                                value={field.minSelected}
                                onChange={(v) => onChange('minSelected', v)}
                                min={0}
                            />
                            <ConfigNumber
                                label="Max Selected"
                                value={field.maxSelected}
                                onChange={(v) => onChange('maxSelected', v)}
                                min={0}
                            />
                        </div>
                    )}
                </>
            );

        case 'checkbox':
            return (
                <ConfigCheckbox
                    label="Default Checked"
                    checked={field.defaultValue ?? false}
                    onChange={(v) => onChange('defaultValue', v)}
                />
            );

        case 'file':
            return (
                <>
                    <ConfigInput
                        label="Accepted File Types"
                        value={field.accept?.join(', ') ?? ''}
                        onChange={(v) => onChange('accept', v ? v.split(',').map((s) => s.trim()) : undefined)}
                        placeholder=".pdf, .doc, .docx"
                        helpText="Comma-separated file extensions"
                    />
                    <ConfigNumber
                        label="Max File Size (MB)"
                        value={field.maxSize ? field.maxSize / (1024 * 1024) : undefined}
                        onChange={(v) => onChange('maxSize', v ? v * 1024 * 1024 : undefined)}
                        min={0}
                        step={0.5}
                    />
                    <ConfigCheckbox
                        label="Allow Multiple Files"
                        checked={field.multiple ?? false}
                        onChange={(v) => onChange('multiple', v)}
                    />
                    {field.multiple && (
                        <ConfigNumber
                            label="Max Files"
                            value={field.maxFiles}
                            onChange={(v) => onChange('maxFiles', v)}
                            min={1}
                        />
                    )}
                </>
            );

        case 'heading':
            return (
                <>
                    <ConfigInput
                        label="Heading Text"
                        value={field.content ?? field.label ?? ''}
                        onChange={(v) => {
                            onChange('content', v);
                            onChange('label', v);
                        }}
                        placeholder="Section Heading"
                    />
                    <ConfigSelect
                        label="Heading Level"
                        value={String(field.level ?? 2)}
                        onChange={(v) => onChange('level', parseInt(v))}
                        options={[
                            { value: '1', label: 'H1 - Largest' },
                            { value: '2', label: 'H2' },
                            { value: '3', label: 'H3' },
                            { value: '4', label: 'H4' },
                            { value: '5', label: 'H5' },
                            { value: '6', label: 'H6 - Smallest' },
                        ]}
                    />
                </>
            );

        case 'rating':
            return (
                <>
                    <div className="grid grid-cols-2 gap-3">
                        <ConfigNumber
                            label="Min"
                            value={field.min ?? 1}
                            onChange={(v) => onChange('min', v)}
                            min={0}
                        />
                        <ConfigNumber
                            label="Max"
                            value={field.max ?? 5}
                            onChange={(v) => onChange('max', v)}
                            min={1}
                            max={10}
                        />
                    </div>
                    <ConfigNumber
                        label="Default Value"
                        value={field.defaultValue}
                        onChange={(v) => onChange('defaultValue', v)}
                        min={field.min ?? 0}
                        max={field.max ?? 5}
                    />
                </>
            );

        default:
            return null;
    }
}

interface SectionConfigProps {
    section: FormSection;
}

function SectionConfig({ section }: SectionConfigProps) {
    const { updateSection } = useBuilder();

    return (
        <div className="space-y-6">
            <ConfigInput
                label="Section Title"
                value={section.title ?? ''}
                onChange={(v) => updateSection(section.id, { title: v })}
                placeholder="Section title"
            />
            <ConfigTextarea
                label="Description"
                value={section.description ?? ''}
                onChange={(v) => updateSection(section.id, { description: v })}
                placeholder="Optional section description"
            />
        </div>
    );
}

// Options Editor for select/radio/checkbox_group
interface OptionsEditorProps {
    options: FieldOption[];
    onChange: (options: FieldOption[]) => void;
}

function OptionsEditor({ options, onChange }: OptionsEditorProps) {
    const addOption = () => {
        const newOption: FieldOption = {
            value: `option${options.length + 1}`,
            label: `Option ${options.length + 1}`,
        };
        onChange([...options, newOption]);
    };

    const updateOption = (index: number, updates: Partial<FieldOption>) => {
        const newOptions = options.map((opt, i) =>
            i === index ? { ...opt, ...updates } : opt
        );
        onChange(newOptions);
    };

    const removeOption = (index: number) => {
        onChange(options.filter((_, i) => i !== index));
    };

    return (
        <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">Options</label>
            <div className="space-y-2">
                {options.map((option, index) => (
                    <div key={index} className="flex items-center gap-2">
                        <input
                            type="text"
                            value={option.label}
                            onChange={(e) => updateOption(index, { label: e.target.value })}
                            placeholder="Label"
                            className="flex-1 px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <input
                            type="text"
                            value={option.value}
                            onChange={(e) => updateOption(index, { value: e.target.value })}
                            placeholder="Value"
                            className="w-24 px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <button
                            onClick={() => removeOption(index)}
                            className="p-1 text-gray-400 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 rounded"
                        >
                            <Trash2 className="w-4 h-4" />
                        </button>
                    </div>
                ))}
            </div>
            <Button variant="ghost" size="sm" onClick={addOption}>
                <Plus className="w-3 h-3 mr-1" />
                Add Option
            </Button>
        </div>
    );
}

// Config Input Components
interface ConfigInputProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    type?: string;
    error?: string;
    helpText?: string;
}

function ConfigInput({ label, value, onChange, placeholder, type = 'text', error, helpText }: ConfigInputProps) {
    return (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-gray-700">{label}</label>
            <input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className={clsx(
                    'block w-full px-3 py-2 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500',
                    error ? 'border-red-300' : 'border-gray-300'
                )}
            />
            {error && <p className="text-xs text-red-600">{error}</p>}
            {helpText && !error && <p className="text-xs text-gray-500">{helpText}</p>}
        </div>
    );
}

interface ConfigTextareaProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
}

function ConfigTextarea({ label, value, onChange, placeholder }: ConfigTextareaProps) {
    return (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-gray-700">{label}</label>
            <textarea
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                rows={2}
                className="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
        </div>
    );
}

interface ConfigNumberProps {
    label: string;
    value: number | undefined;
    onChange: (value: number | undefined) => void;
    min?: number;
    max?: number;
    step?: number;
}

function ConfigNumber({ label, value, onChange, min, max, step = 1 }: ConfigNumberProps) {
    return (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-gray-700">{label}</label>
            <input
                type="number"
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value ? Number(e.target.value) : undefined)}
                min={min}
                max={max}
                step={step}
                className="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
        </div>
    );
}

interface ConfigCheckboxProps {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}

function ConfigCheckbox({ label, checked, onChange }: ConfigCheckboxProps) {
    return (
        <label className="flex items-center gap-2 cursor-pointer">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
            />
            <span className="text-sm text-gray-700">{label}</span>
        </label>
    );
}

interface ConfigSelectProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}

function ConfigSelect({ label, value, onChange, options }: ConfigSelectProps) {
    return (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-gray-700">{label}</label>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="block w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                {options.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                        {opt.label}
                    </option>
                ))}
            </select>
        </div>
    );
}
