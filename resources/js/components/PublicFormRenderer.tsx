import { clsx } from 'clsx';
import { AlertCircle, Check, ChevronLeft, ChevronRight, Loader2, Star } from 'lucide-react';
import { useCallback, useState } from 'react';
import type { FieldOption, FormField, FormSchema, FormSection } from '../../types/form-schema';
import { FIELDS_WITH_OPTIONS, isPresentationalField } from '../../types/form-schema';
import api from '../../lib/api';

interface PublicFormRendererProps {
    schema: FormSchema;
    slug: string;
    successMessage?: string;
}

interface FormData {
    [key: string]: string | number | boolean | string[] | File[];
}

interface FormErrors {
    [key: string]: string[];
}

export function PublicFormRenderer({ schema, slug, successMessage }: PublicFormRendererProps) {
    const [currentStep, setCurrentStep] = useState(0);
    const [formData, setFormData] = useState<FormData>({});
    const [files, setFiles] = useState<{ [key: string]: File[] }>({});
    const [errors, setErrors] = useState<FormErrors>({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isSubmitted, setIsSubmitted] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [touched, setTouched] = useState<Set<string>>(new Set());

    const sections = schema.sections || [];
    const isMultiStep = sections.length > 1;
    const currentSection = sections[currentStep];
    const isLastStep = currentStep === sections.length - 1;

    const handleFieldChange = useCallback((key: string, value: string | number | boolean | string[]) => {
        setFormData((prev) => ({ ...prev, [key]: value }));
        setTouched((prev) => new Set(prev).add(key));
        setErrors((prev) => {
            const newErrors = { ...prev };
            delete newErrors[key];
            return newErrors;
        });
    }, []);

    const handleFileChange = useCallback((key: string, fileList: FileList | null) => {
        if (!fileList) {
            setFiles((prev) => {
                const newFiles = { ...prev };
                delete newFiles[key];
                return newFiles;
            });
            return;
        }
        setFiles((prev) => ({ ...prev, [key]: Array.from(fileList) }));
        setErrors((prev) => {
            const newErrors = { ...prev };
            delete newErrors[key];
            return newErrors;
        });
    }, []);

    const validateStep = useCallback((section: FormSection): boolean => {
        const stepErrors: FormErrors = {};

        for (const field of section.fields) {
            if (isPresentationalField(field.type)) continue;
            if (!isFieldVisible(field, formData)) continue;

            const value = formData[field.key];
            const isRequired = field.required || shouldBeRequired(field, formData);

            if (isRequired) {
                if (field.type === 'file') {
                    if (!files[field.key] || files[field.key].length === 0) {
                        stepErrors[field.key] = [field.customError || `${field.label || field.key} is required`];
                    }
                } else if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
                    stepErrors[field.key] = [field.customError || `${field.label || field.key} is required`];
                }
            }
        }

        setErrors(stepErrors);
        return Object.keys(stepErrors).length === 0;
    }, [formData, files]);

    const handleNext = useCallback(() => {
        if (validateStep(currentSection)) {
            setCurrentStep((prev) => Math.min(prev + 1, sections.length - 1));
        }
    }, [currentSection, sections.length, validateStep]);

    const handlePrev = useCallback(() => {
        setCurrentStep((prev) => Math.max(prev - 1, 0));
    }, []);

    const handleSubmit = useCallback(async (e: React.FormEvent) => {
        e.preventDefault();

        if (!validateStep(currentSection)) {
            return;
        }

        setIsSubmitting(true);
        setSubmitError(null);

        try {
            const formDataObj = new FormData();

            // Add form data
            for (const [key, value] of Object.entries(formData)) {
                if (Array.isArray(value)) {
                    value.forEach((v) => formDataObj.append(`${key}[]`, String(v)));
                } else {
                    formDataObj.append(key, String(value));
                }
            }

            // Add files
            for (const [key, fileList] of Object.entries(files)) {
                fileList.forEach((file) => {
                    formDataObj.append(`${key}[]`, file);
                });
            }

            await api.post(`/public/forms/${slug}/submit`, formDataObj, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            setIsSubmitted(true);
        } catch (error: any) {
            if (error.response?.status === 422) {
                const serverErrors = error.response.data.errors || {};
                setErrors(serverErrors);
            } else {
                setSubmitError(error.response?.data?.message || 'Submission failed. Please try again.');
            }
        } finally {
            setIsSubmitting(false);
        }
    }, [currentSection, formData, files, slug, validateStep]);

    if (isSubmitted) {
        return (
            <div className="max-w-2xl mx-auto p-6">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
                    <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <Check className="w-8 h-8 text-green-600" />
                    </div>
                    <h2 className="text-2xl font-bold text-gray-900 mb-2">Thank You!</h2>
                    <p className="text-gray-600">{successMessage || 'Your submission has been received.'}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="w-full max-w-2xl mx-auto px-4 sm:px-6 py-4 sm:py-6">
            <form 
                onSubmit={handleSubmit} 
                className="bg-white rounded-xl shadow-sm border border-gray-200"
                noValidate
                aria-label={schema.metadata.title}
            >
                {/* Header */}
                <div className="p-4 sm:p-6 border-b border-gray-200">
                    <h1 className="text-xl sm:text-2xl font-bold text-gray-900">{schema.metadata.title}</h1>
                    {schema.metadata.description && (
                        <p className="text-gray-600 mt-2 text-sm sm:text-base">{schema.metadata.description}</p>
                    )}
                </div>

                {/* Progress bar */}
                {isMultiStep && schema.settings?.showProgressBar && (
                    <div 
                        className="px-4 sm:px-6 py-3 bg-gray-50 border-b border-gray-200"
                        role="progressbar"
                        aria-valuenow={currentStep + 1}
                        aria-valuemin={1}
                        aria-valuemax={sections.length}
                        aria-label={`Step ${currentStep + 1} of ${sections.length}`}
                    >
                        <div className="flex items-center justify-between text-sm text-gray-600 mb-2">
                            <span>Step {currentStep + 1} of {sections.length}</span>
                            <span>{Math.round(((currentStep + 1) / sections.length) * 100)}%</span>
                        </div>
                        <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div
                                className="h-full bg-blue-600 transition-all duration-300"
                                style={{ width: `${((currentStep + 1) / sections.length) * 100}%` }}
                            />
                        </div>
                    </div>
                )}

                {/* Section */}
                <div className="p-4 sm:p-6">
                    {currentSection && (
                        <fieldset>
                            {currentSection.title && (
                                <legend className="text-lg font-semibold text-gray-900 mb-1">{currentSection.title}</legend>
                            )}
                            {currentSection.description && (
                                <p className="text-gray-600 text-sm mb-4">{currentSection.description}</p>
                            )}

                            <div className="space-y-4 sm:space-y-6">
                                {currentSection.fields.map((field) => (
                                    <FieldInput
                                        key={field.id}
                                        field={field}
                                        value={formData[field.key]}
                                        files={files[field.key]}
                                        error={errors[field.key]}
                                        onChange={handleFieldChange}
                                        onFileChange={handleFileChange}
                                        formData={formData}
                                    />
                                ))}
                            </div>
                        </fieldset>
                    )}
                </div>

                {/* Submit error */}
                {submitError && (
                    <div 
                        className="mx-4 sm:mx-6 mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start gap-3"
                        role="alert"
                        aria-live="polite"
                    >
                        <AlertCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" aria-hidden="true" />
                        <p className="text-red-800 text-sm">{submitError}</p>
                    </div>
                )}

                {/* Footer */}
                <div className="px-4 sm:px-6 py-4 bg-gray-50 border-t border-gray-200 flex flex-col sm:flex-row items-center justify-between gap-3 sm:gap-0 rounded-b-xl">
                    {isMultiStep && currentStep > 0 ? (
                        <button
                            type="button"
                            onClick={handlePrev}
                            className="flex items-center gap-1 px-4 py-2 text-gray-700 hover:text-gray-900 order-2 sm:order-1"
                            aria-label="Go to previous step"
                        >
                            <ChevronLeft className="w-4 h-4" aria-hidden="true" />
                            Previous
                        </button>
                    ) : (
                        <div className="hidden sm:block" />
                    )}

                    {isMultiStep && !isLastStep ? (
                        <button
                            type="button"
                            onClick={handleNext}
                            className="flex items-center gap-1 px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 w-full sm:w-auto justify-center order-1 sm:order-2"
                            aria-label="Go to next step"
                        >
                            Next
                            <ChevronRight className="w-4 h-4" aria-hidden="true" />
                        </button>
                    ) : (
                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 w-full sm:w-auto justify-center order-1 sm:order-2"
                            aria-busy={isSubmitting}
                        >
                            {isSubmitting && <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />}
                            {schema.settings?.submitButtonText || 'Submit'}
                        </button>
                    )}
                </div>
            </form>
        </div>
    );
}

interface FieldInputProps {
    field: FormField;
    value: string | number | boolean | string[] | undefined;
    files?: File[];
    error?: string[];
    onChange: (key: string, value: string | number | boolean | string[]) => void;
    onFileChange: (key: string, files: FileList | null) => void;
    formData: FormData;
}

function FieldInput({ field, value, files, error, onChange, onFileChange, formData }: FieldInputProps) {
    if (!isFieldVisible(field, formData)) {
        return null;
    }

    const isRequired = field.required || shouldBeRequired(field, formData);
    const hasError = error && error.length > 0;
    const fieldId = `field-${field.id}`;
    const errorId = `${fieldId}-error`;
    const helpId = `${fieldId}-help`;

    const inputClasses = clsx(
        'block w-full px-3 py-2.5 border rounded-lg shadow-sm transition-colors',
        'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
        'text-sm sm:text-base',
        hasError ? 'border-red-300 bg-red-50' : 'border-gray-300'
    );

    const renderInput = () => {
        const ariaProps = {
            'aria-invalid': hasError || undefined,
            'aria-describedby': [hasError ? errorId : null, field.helpText ? helpId : null].filter(Boolean).join(' ') || undefined,
        };

        switch (field.type) {
            case 'text':
            case 'email':
            case 'phone':
            case 'url':
                return (
                    <input
                        id={fieldId}
                        type={field.type === 'phone' ? 'tel' : field.type}
                        value={(value as string) ?? ''}
                        onChange={(e) => onChange(field.key, e.target.value)}
                        placeholder={field.placeholder}
                        className={inputClasses}
                        required={isRequired}
                        minLength={field.type === 'text' ? (field as any).minLength : undefined}
                        maxLength={field.type === 'text' ? (field as any).maxLength : undefined}
                        pattern={['text', 'phone'].includes(field.type) ? (field as any).pattern : undefined}
                        {...ariaProps}
                    />
                );

            case 'textarea':
                return (
                    <textarea
                        id={fieldId}
                        value={(value as string) ?? ''}
                        onChange={(e) => onChange(field.key, e.target.value)}
                        placeholder={field.placeholder}
                        rows={(field as any).rows ?? 3}
                        className={inputClasses}
                        required={isRequired}
                        {...ariaProps}
                    />
                );

            case 'number':
                return (
                    <input
                        id={fieldId}
                        type="number"
                        value={(value as number) ?? ''}
                        onChange={(e) => onChange(field.key, e.target.value ? Number(e.target.value) : '')}
                        placeholder={field.placeholder}
                        min={(field as any).min}
                        max={(field as any).max}
                        step={(field as any).step}
                        className={inputClasses}
                        required={isRequired}
                        {...ariaProps}
                    />
                );

            case 'date':
                return (
                    <input
                        id={fieldId}
                        type="date"
                        value={(value as string) ?? ''}
                        onChange={(e) => onChange(field.key, e.target.value)}
                        min={(field as any).min}
                        max={(field as any).max}
                        className={inputClasses}
                        required={isRequired}
                        {...ariaProps}
                    />
                );

            case 'select':
                return (
                    <select
                        id={fieldId}
                        value={(value as string) ?? ''}
                        onChange={(e) => onChange(field.key, e.target.value)}
                        className={inputClasses}
                        required={isRequired}
                        {...ariaProps}
                    >
                        <option value="">{field.placeholder || 'Select an option'}</option>
                        {(field as any).options?.map((opt: FieldOption) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </select>
                );

            case 'radio':
                return (
                    <div className="space-y-2" role="radiogroup" aria-labelledby={`${fieldId}-label`}>
                        {(field as any).options?.map((opt: FieldOption, idx: number) => (
                            <label key={opt.value} className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name={field.key}
                                    value={opt.value}
                                    checked={value === opt.value}
                                    onChange={(e) => onChange(field.key, e.target.value)}
                                    className="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                    required={isRequired && idx === 0}
                                />
                                <span className="text-sm text-gray-700">{opt.label}</span>
                            </label>
                        ))}
                    </div>
                );

            case 'checkbox_group':
                const selectedValues = (value as string[]) ?? [];
                return (
                    <div className="space-y-2" role="group" aria-labelledby={`${fieldId}-label`}>
                        {(field as any).options?.map((opt: FieldOption) => (
                            <label key={opt.value} className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    value={opt.value}
                                    checked={selectedValues.includes(String(opt.value))}
                                    onChange={(e) => {
                                        const newValues = e.target.checked
                                            ? [...selectedValues, String(opt.value)]
                                            : selectedValues.filter((v) => v !== String(opt.value));
                                        onChange(field.key, newValues);
                                    }}
                                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                />
                                <span className="text-sm text-gray-700">{opt.label}</span>
                            </label>
                        ))}
                    </div>
                );

            case 'checkbox':
                return (
                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            id={fieldId}
                            type="checkbox"
                            checked={Boolean(value)}
                            onChange={(e) => onChange(field.key, e.target.checked)}
                            className="w-4 h-4 mt-0.5 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                            required={isRequired}
                            {...ariaProps}
                        />
                        <span className="text-sm text-gray-700">{field.label}</span>
                    </label>
                );

            case 'file':
                return (
                    <div>
                        <input
                            type="file"
                            onChange={(e) => onFileChange(field.key, e.target.files)}
                            accept={(field as any).accept?.join(',')}
                            multiple={(field as any).multiple}
                            className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                            required={isRequired && (!files || files.length === 0)}
                        />
                        {files && files.length > 0 && (
                            <div className="mt-2 text-sm text-gray-600">
                                {files.map((f, i) => (
                                    <div key={i}>{f.name}</div>
                                ))}
                            </div>
                        )}
                    </div>
                );

            case 'heading':
                const HeadingTag = `h${(field as any).level ?? 2}` as keyof JSX.IntrinsicElements;
                const headingSizes: Record<number, string> = {
                    1: 'text-3xl', 2: 'text-2xl', 3: 'text-xl', 4: 'text-lg', 5: 'text-base', 6: 'text-sm',
                };
                return (
                    <HeadingTag className={clsx('font-bold text-gray-900', headingSizes[(field as any).level ?? 2])}>
                        {(field as any).content || field.label}
                    </HeadingTag>
                );

            case 'rating':
                const max = (field as any).max ?? 5;
                const currentRating = (value as number) ?? 0;
                return (
                    <div className="flex items-center gap-1">
                        {Array.from({ length: max }, (_, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => onChange(field.key, i + 1)}
                                className={clsx(
                                    'p-1 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded',
                                    i < currentRating ? 'text-yellow-400' : 'text-gray-300 hover:text-yellow-300'
                                )}
                            >
                                <Star className="w-6 h-6 fill-current" />
                            </button>
                        ))}
                    </div>
                );

            default:
                return null;
        }
    };

    // Checkbox has inline label
    if (field.type === 'checkbox') {
        return (
            <div>
                {renderInput()}
                {field.helpText && <p id={helpId} className="mt-1 text-xs text-gray-500">{field.helpText}</p>}
                {hasError && (
                    <p id={errorId} className="mt-1 text-sm text-red-600 flex items-center gap-1" role="alert">
                        <AlertCircle className="w-4 h-4" aria-hidden="true" />
                        {error[0]}
                    </p>
                )}
            </div>
        );
    }

    // Heading has no label
    if (field.type === 'heading') {
        return <div>{renderInput()}</div>;
    }

    return (
        <div className={clsx(
            field.width === 'half' && 'sm:w-1/2',
            field.width === 'third' && 'sm:w-1/3',
            field.width === 'quarter' && 'sm:w-1/4'
        )}>
            <label 
                id={`${fieldId}-label`}
                htmlFor={fieldId} 
                className="block text-sm font-medium text-gray-700 mb-1"
            >
                {field.label}
                {isRequired && <span className="text-red-500 ml-1" aria-hidden="true">*</span>}
                {isRequired && <span className="sr-only">(required)</span>}
            </label>
            {renderInput()}
            {field.helpText && <p id={helpId} className="mt-1 text-xs text-gray-500">{field.helpText}</p>}
            {hasError && (
                <p id={errorId} className="mt-1 text-sm text-red-600 flex items-center gap-1" role="alert">
                    <AlertCircle className="w-4 h-4" aria-hidden="true" />
                    {error[0]}
                </p>
            )}
        </div>
    );
}

// Helper functions for conditional logic
function isFieldVisible(field: FormField, formData: FormData): boolean {
    const conditions = field.conditions ?? [];

    for (const condition of conditions) {
        const action = condition.action;
        if (action !== 'show' && action !== 'hide') continue;

        const targetValue = formData[condition.field];
        const conditionMet = evaluateCondition(condition, targetValue);

        if (action === 'show' && !conditionMet) return false;
        if (action === 'hide' && conditionMet) return false;
    }

    return true;
}

function shouldBeRequired(field: FormField, formData: FormData): boolean {
    const conditions = field.conditions ?? [];

    for (const condition of conditions) {
        if (condition.action !== 'require') continue;

        const targetValue = formData[condition.field];
        if (evaluateCondition(condition, targetValue)) return true;
    }

    return false;
}

function evaluateCondition(condition: any, value: any): boolean {
    const operator = condition.operator ?? 'equals';
    const conditionValue = condition.value;

    switch (operator) {
        case 'equals': return value == conditionValue;
        case 'not_equals': return value != conditionValue;
        case 'contains': return typeof value === 'string' && value.includes(String(conditionValue));
        case 'not_contains': return typeof value === 'string' && !value.includes(String(conditionValue));
        case 'greater_than': return Number(value) > Number(conditionValue);
        case 'less_than': return Number(value) < Number(conditionValue);
        case 'is_empty': return value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
        case 'is_not_empty': return value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0);
        case 'in': return Array.isArray(conditionValue) && conditionValue.includes(value);
        case 'not_in': return Array.isArray(conditionValue) && !conditionValue.includes(value);
        default: return false;
    }
}
