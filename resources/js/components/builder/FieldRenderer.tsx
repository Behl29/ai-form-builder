import { clsx } from 'clsx';
import { Star } from 'lucide-react';
import type { FormField } from '../../types/form-schema';

interface FieldRendererProps {
    field: FormField;
    isBuilder?: boolean;
}

export function FieldRenderer({ field, isBuilder = false }: FieldRendererProps) {
    const labelClasses = 'block text-sm font-medium text-gray-700 mb-1';
    const inputClasses = 'block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm disabled:bg-gray-50';
    const helpTextClasses = 'mt-1 text-xs text-gray-500';

    const renderLabel = () => {
        if (field.type === 'heading') return null;
        return (
            <label className={labelClasses}>
                {field.label || 'Untitled Field'}
                {field.required && <span className="text-red-500 ml-1">*</span>}
            </label>
        );
    };

    const renderHelpText = () => {
        if (!field.helpText) return null;
        return <p className={helpTextClasses}>{field.helpText}</p>;
    };

    const renderField = () => {
        switch (field.type) {
            case 'text':
                return (
                    <input
                        type="text"
                        placeholder={field.placeholder}
                        defaultValue={field.defaultValue}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'textarea':
                return (
                    <textarea
                        placeholder={field.placeholder}
                        defaultValue={field.defaultValue}
                        rows={field.rows ?? 3}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'number':
                return (
                    <input
                        type="number"
                        placeholder={field.placeholder}
                        defaultValue={field.defaultValue}
                        min={field.min}
                        max={field.max}
                        step={field.step}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'email':
                return (
                    <input
                        type="email"
                        placeholder={field.placeholder || 'email@example.com'}
                        defaultValue={field.defaultValue}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'phone':
                return (
                    <input
                        type="tel"
                        placeholder={field.placeholder || '+1 (555) 000-0000'}
                        defaultValue={field.defaultValue}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'date':
                return (
                    <input
                        type="date"
                        defaultValue={field.defaultValue}
                        min={field.min}
                        max={field.max}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'url':
                return (
                    <input
                        type="url"
                        placeholder={field.placeholder || 'https://example.com'}
                        defaultValue={field.defaultValue}
                        className={inputClasses}
                        disabled={isBuilder}
                    />
                );

            case 'select':
                return (
                    <select className={inputClasses} disabled={isBuilder} defaultValue={field.defaultValue as string}>
                        <option value="">{field.placeholder || 'Select an option'}</option>
                        {field.options?.map((option, i) => (
                            <option key={i} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                );

            case 'radio':
                return (
                    <div className="space-y-2">
                        {field.options?.map((option, i) => (
                            <label key={i} className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name={field.id}
                                    value={option.value}
                                    defaultChecked={field.defaultValue === option.value}
                                    disabled={isBuilder}
                                    className="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                />
                                <span className="text-sm text-gray-700">{option.label}</span>
                            </label>
                        ))}
                        {(!field.options || field.options.length === 0) && (
                            <p className="text-sm text-gray-400 italic">No options defined</p>
                        )}
                    </div>
                );

            case 'checkbox_group':
                return (
                    <div className="space-y-2">
                        {field.options?.map((option, i) => (
                            <label key={i} className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    value={option.value}
                                    defaultChecked={field.defaultValue?.includes(option.value)}
                                    disabled={isBuilder}
                                    className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                />
                                <span className="text-sm text-gray-700">{option.label}</span>
                            </label>
                        ))}
                        {(!field.options || field.options.length === 0) && (
                            <p className="text-sm text-gray-400 italic">No options defined</p>
                        )}
                    </div>
                );

            case 'checkbox':
                return (
                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            defaultChecked={field.defaultValue}
                            disabled={isBuilder}
                            className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                        />
                        <span className="text-sm text-gray-700">{field.label}</span>
                    </label>
                );

            case 'file':
                return (
                    <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                        <input
                            type="file"
                            accept={field.accept?.join(',')}
                            multiple={field.multiple}
                            disabled={isBuilder}
                            className="hidden"
                            id={field.id}
                        />
                        <label
                            htmlFor={field.id}
                            className={clsx(
                                'cursor-pointer text-sm',
                                isBuilder ? 'text-gray-400' : 'text-blue-600 hover:text-blue-700'
                            )}
                        >
                            Click to upload or drag and drop
                        </label>
                        {field.accept && (
                            <p className="text-xs text-gray-500 mt-1">
                                Accepted: {field.accept.join(', ')}
                            </p>
                        )}
                        {field.maxSize && (
                            <p className="text-xs text-gray-500">
                                Max size: {formatFileSize(field.maxSize)}
                            </p>
                        )}
                    </div>
                );

            case 'heading':
                const HeadingTag = `h${field.level ?? 2}` as keyof JSX.IntrinsicElements;
                const headingSizes: Record<number, string> = {
                    1: 'text-3xl',
                    2: 'text-2xl',
                    3: 'text-xl',
                    4: 'text-lg',
                    5: 'text-base',
                    6: 'text-sm',
                };
                return (
                    <HeadingTag className={clsx('font-bold text-gray-900', headingSizes[field.level ?? 2])}>
                        {field.content || field.label || 'Section Heading'}
                    </HeadingTag>
                );

            case 'rating':
                const max = field.max ?? 5;
                const defaultVal = field.defaultValue ?? 0;
                return (
                    <div className="flex items-center gap-1">
                        {Array.from({ length: max }, (_, i) => (
                            <button
                                key={i}
                                type="button"
                                disabled={isBuilder}
                                className={clsx(
                                    'p-1 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded',
                                    i < defaultVal ? 'text-yellow-400' : 'text-gray-300'
                                )}
                            >
                                <Star className="w-6 h-6 fill-current" />
                            </button>
                        ))}
                    </div>
                );

            default:
                return <p className="text-gray-500 text-sm">Unknown field type: {(field as FormField).type}</p>;
        }
    };

    // Checkbox has label inline
    if (field.type === 'checkbox') {
        return (
            <div>
                {renderField()}
                {renderHelpText()}
            </div>
        );
    }

    return (
        <div>
            {renderLabel()}
            {renderField()}
            {renderHelpText()}
        </div>
    );
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
