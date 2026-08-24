import { clsx } from 'clsx';
import { AlertCircle, Check, Code, Copy } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FormSchema } from '../../types/form-schema';
import { FIELD_TYPES, SCHEMA_VERSION } from '../../types/form-schema';
import { Button } from '../ui';

interface JsonEditorProps {
    schema: FormSchema;
    onUpdate: (schema: FormSchema) => void;
    onClose: () => void;
}

interface ValidationError {
    path: string;
    message: string;
    line?: number;
}

export function JsonEditor({ schema, onUpdate, onClose }: JsonEditorProps) {
    const [jsonText, setJsonText] = useState('');
    const [errors, setErrors] = useState<ValidationError[]>([]);
    const [isValid, setIsValid] = useState(true);
    const [copied, setCopied] = useState(false);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const lineNumbersRef = useRef<HTMLDivElement>(null);

    // Initialize with formatted JSON
    useEffect(() => {
        setJsonText(JSON.stringify(schema, null, 2));
        setErrors([]);
        setIsValid(true);
    }, [schema]);

    // Sync scroll between textarea and line numbers
    const handleScroll = useCallback(() => {
        if (textareaRef.current && lineNumbersRef.current) {
            lineNumbersRef.current.scrollTop = textareaRef.current.scrollTop;
        }
    }, []);

    // Line numbers
    const lineCount = useMemo(() => jsonText.split('\n').length, [jsonText]);

    // Find line number for a JSON path
    const findLineForPath = useCallback((text: string, path: string): number | undefined => {
        if (!path) return undefined;

        const lines = text.split('\n');
        const pathParts = path.replace(/\[(\d+)\]/g, '.$1').split('.');

        let currentDepth = 0;

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const openBraces = (line.match(/[{[]/g) || []).length;
            const closeBraces = (line.match(/[}\]]/g) || []).length;

            // Check if this line contains our target key
            for (let p = 0; p < pathParts.length; p++) {
                const part = pathParts[p];
                const isArrayIndex = /^\d+$/.test(part);

                if (isArrayIndex) {
                    // For array indices, we need to count array elements
                    continue;
                }

                const keyPattern = new RegExp(`"${part}"\\s*:`);
                if (keyPattern.test(line)) {
                    if (p === pathParts.length - 1) {
                        return i + 1;
                    }
                }
            }

            currentDepth += openBraces - closeBraces;
        }

        // Fallback: search for the last part of the path
        const lastPart = pathParts[pathParts.length - 1];
        if (!/^\d+$/.test(lastPart)) {
            for (let i = 0; i < lines.length; i++) {
                if (lines[i].includes(`"${lastPart}"`)) {
                    return i + 1;
                }
            }
        }

        return undefined;
    }, []);

    const validateJson = useCallback((text: string): { valid: boolean; schema?: FormSchema; errors: ValidationError[] } => {
        const validationErrors: ValidationError[] = [];

        // Parse JSON
        let parsed: unknown;
        try {
            parsed = JSON.parse(text);
        } catch (e) {
            const error = e as SyntaxError;
            const match = error.message.match(/position (\d+)/);
            let line = 1;
            if (match) {
                const position = parseInt(match[1]);
                line = text.substring(0, position).split('\n').length;
            }
            return {
                valid: false,
                errors: [{ path: '', message: `JSON syntax error: ${error.message}`, line }],
            };
        }

        if (typeof parsed !== 'object' || parsed === null) {
            return {
                valid: false,
                errors: [{ path: '', message: 'Schema must be an object' }],
            };
        }

        const obj = parsed as Record<string, unknown>;

        // Validate schema version
        if (!obj.schemaVersion) {
            validationErrors.push({
                path: 'schemaVersion',
                message: 'schemaVersion is required',
                line: findLineForPath(text, 'schemaVersion'),
            });
        } else if (obj.schemaVersion !== SCHEMA_VERSION) {
            validationErrors.push({
                path: 'schemaVersion',
                message: `schemaVersion must be "${SCHEMA_VERSION}"`,
                line: findLineForPath(text, 'schemaVersion'),
            });
        }

        // Validate metadata
        if (!obj.metadata || typeof obj.metadata !== 'object') {
            validationErrors.push({
                path: 'metadata',
                message: 'metadata is required and must be an object',
                line: findLineForPath(text, 'metadata'),
            });
        } else {
            const metadata = obj.metadata as Record<string, unknown>;
            if (typeof metadata.title !== 'string' || !metadata.title.trim()) {
                validationErrors.push({
                    path: 'metadata.title',
                    message: 'metadata.title is required and must be a non-empty string',
                    line: findLineForPath(text, 'metadata.title'),
                });
            }
        }

        // Validate sections
        if (!Array.isArray(obj.sections)) {
            validationErrors.push({
                path: 'sections',
                message: 'sections must be an array',
                line: findLineForPath(text, 'sections'),
            });
        } else {
            const fieldIds = new Set<string>();
            const fieldKeys = new Set<string>();

            obj.sections.forEach((section, sIndex) => {
                const sectionPath = `sections[${sIndex}]`;

                if (typeof section !== 'object' || section === null) {
                    validationErrors.push({
                        path: sectionPath,
                        message: 'Section must be an object',
                        line: findLineForPath(text, sectionPath),
                    });
                    return;
                }

                const sec = section as Record<string, unknown>;

                if (!sec.id || typeof sec.id !== 'string') {
                    validationErrors.push({
                        path: `${sectionPath}.id`,
                        message: 'Section id is required',
                        line: findLineForPath(text, `${sectionPath}.id`),
                    });
                }

                if (!Array.isArray(sec.fields)) {
                    validationErrors.push({
                        path: `${sectionPath}.fields`,
                        message: 'Section fields must be an array',
                        line: findLineForPath(text, `${sectionPath}.fields`),
                    });
                    return;
                }

                sec.fields.forEach((field, fIndex) => {
                    const fieldPath = `${sectionPath}.fields[${fIndex}]`;

                    if (typeof field !== 'object' || field === null) {
                        validationErrors.push({
                            path: fieldPath,
                            message: 'Field must be an object',
                            line: findLineForPath(text, fieldPath),
                        });
                        return;
                    }

                    const f = field as Record<string, unknown>;

                    // Validate field id
                    if (!f.id || typeof f.id !== 'string') {
                        validationErrors.push({
                            path: `${fieldPath}.id`,
                            message: 'Field id is required',
                            line: findLineForPath(text, `${fieldPath}.id`),
                        });
                    } else if (fieldIds.has(f.id as string)) {
                        validationErrors.push({
                            path: `${fieldPath}.id`,
                            message: `Duplicate field id: "${f.id}"`,
                            line: findLineForPath(text, `${fieldPath}.id`),
                        });
                    } else {
                        fieldIds.add(f.id as string);
                    }

                    // Validate field key
                    if (!f.key || typeof f.key !== 'string') {
                        validationErrors.push({
                            path: `${fieldPath}.key`,
                            message: 'Field key is required',
                            line: findLineForPath(text, `${fieldPath}.key`),
                        });
                    } else {
                        if (fieldKeys.has(f.key as string)) {
                            validationErrors.push({
                                path: `${fieldPath}.key`,
                                message: `Duplicate field key: "${f.key}"`,
                                line: findLineForPath(text, `${fieldPath}.key`),
                            });
                        } else {
                            if (!/^[a-z][a-z0-9_]*$/.test(f.key as string)) {
                                validationErrors.push({
                                    path: `${fieldPath}.key`,
                                    message: 'Field key must start with lowercase letter and contain only lowercase letters, numbers, and underscores',
                                    line: findLineForPath(text, `${fieldPath}.key`),
                                });
                            }
                            fieldKeys.add(f.key as string);
                        }
                    }

                    // Validate field type
                    if (!f.type || typeof f.type !== 'string') {
                        validationErrors.push({
                            path: `${fieldPath}.type`,
                            message: 'Field type is required',
                            line: findLineForPath(text, `${fieldPath}.type`),
                        });
                    } else if (!FIELD_TYPES.includes(f.type as any)) {
                        validationErrors.push({
                            path: `${fieldPath}.type`,
                            message: `Invalid field type: "${f.type}". Valid types: ${FIELD_TYPES.join(', ')}`,
                            line: findLineForPath(text, `${fieldPath}.type`),
                        });
                    } else {
                        // Check options for select/radio/checkbox_group
                        const optionTypes = ['select', 'radio', 'checkbox_group'];
                        if (optionTypes.includes(f.type as string)) {
                            if (!Array.isArray(f.options) || f.options.length === 0) {
                                validationErrors.push({
                                    path: `${fieldPath}.options`,
                                    message: `${f.type} field requires at least one option`,
                                    line: findLineForPath(text, `${fieldPath}.options`),
                                });
                            }
                        }
                    }
                });
            });
        }

        if (validationErrors.length > 0) {
            return { valid: false, errors: validationErrors };
        }

        return { valid: true, schema: parsed as FormSchema, errors: [] };
    }, [findLineForPath]);

    const handleChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
        const text = e.target.value;
        setJsonText(text);

        const result = validateJson(text);
        setErrors(result.errors);
        setIsValid(result.valid);
    }, [validateJson]);

    const handleApply = useCallback(() => {
        const result = validateJson(jsonText);
        if (result.valid && result.schema) {
            onUpdate(result.schema);
            onClose();
        }
    }, [jsonText, validateJson, onUpdate, onClose]);

    const handleFormat = useCallback(() => {
        try {
            const parsed = JSON.parse(jsonText);
            const formatted = JSON.stringify(parsed, null, 2);
            setJsonText(formatted);
            const result = validateJson(formatted);
            setErrors(result.errors);
            setIsValid(result.valid);
        } catch {
            // Keep current text if invalid JSON
        }
    }, [jsonText, validateJson]);

    const handleCopy = useCallback(async () => {
        await navigator.clipboard.writeText(jsonText);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }, [jsonText]);

    const goToLine = useCallback((line: number) => {
        if (!textareaRef.current) return;

        const lines = jsonText.split('\n');
        let position = 0;
        for (let i = 0; i < line - 1 && i < lines.length; i++) {
            position += lines[i].length + 1;
        }

        textareaRef.current.focus();
        textareaRef.current.setSelectionRange(position, position + (lines[line - 1]?.length || 0));

        // Scroll to line
        const lineHeight = 20;
        textareaRef.current.scrollTop = (line - 5) * lineHeight;
    }, [jsonText]);

    return (
        <div className="fixed inset-0 z-50 bg-gray-900/50 flex items-center justify-center p-4">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col">
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <div className="flex items-center gap-2">
                        <Code className="w-5 h-5 text-gray-500" />
                        <h2 className="text-lg font-semibold text-gray-900">JSON Schema Editor</h2>
                    </div>
                    <div className="flex items-center gap-3">
                        {isValid ? (
                            <span className="flex items-center gap-1 text-sm text-green-600 bg-green-50 px-2 py-1 rounded">
                                <Check className="w-4 h-4" />
                                Valid Schema
                            </span>
                        ) : (
                            <span className="flex items-center gap-1 text-sm text-red-600 bg-red-50 px-2 py-1 rounded">
                                <AlertCircle className="w-4 h-4" />
                                {errors.length} error{errors.length !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                </div>

                {/* Editor */}
                <div className="flex-1 flex overflow-hidden">
                    {/* Code area with line numbers */}
                    <div className="flex-1 flex overflow-hidden bg-gray-900">
                        {/* Line numbers */}
                        <div
                            ref={lineNumbersRef}
                            className="w-12 bg-gray-800 text-gray-500 text-right pr-2 py-4 font-mono text-sm select-none overflow-hidden"
                            style={{ lineHeight: '20px' }}
                        >
                            {Array.from({ length: lineCount }, (_, i) => (
                                <div key={i + 1} className={clsx(
                                    errors.some(e => e.line === i + 1) && 'text-red-400 font-bold'
                                )}>
                                    {i + 1}
                                </div>
                            ))}
                        </div>

                        {/* Textarea */}
                        <textarea
                            ref={textareaRef}
                            value={jsonText}
                            onChange={handleChange}
                            onScroll={handleScroll}
                            spellCheck={false}
                            className={clsx(
                                'flex-1 p-4 font-mono text-sm resize-none focus:outline-none',
                                'bg-gray-900 text-gray-100 caret-white',
                                'selection:bg-blue-500/30'
                            )}
                            style={{ lineHeight: '20px', tabSize: 2 }}
                        />
                    </div>

                    {/* Errors panel */}
                    {errors.length > 0 && (
                        <div className="w-80 border-l border-gray-200 bg-red-50 overflow-y-auto">
                            <div className="p-4">
                                <h3 className="text-sm font-medium text-red-800 mb-3">Validation Errors</h3>
                                <div className="space-y-2">
                                    {errors.map((error, index) => (
                                        <button
                                            key={index}
                                            onClick={() => error.line && goToLine(error.line)}
                                            className="w-full text-left p-3 bg-white rounded-lg border border-red-200 hover:border-red-300 transition-colors"
                                        >
                                            {error.path && (
                                                <div className="font-mono text-xs text-red-600 mb-1 break-all">
                                                    {error.path}
                                                    {error.line && (
                                                        <span className="ml-2 text-red-400">
                                                            Line {error.line}
                                                        </span>
                                                    )}
                                                </div>
                                            )}
                                            <div className="text-sm text-red-800">{error.message}</div>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between px-6 py-4 border-t border-gray-200 bg-gray-50">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="sm" onClick={handleFormat}>
                            Format JSON
                        </Button>
                        <Button variant="ghost" size="sm" onClick={handleCopy}>
                            <Copy className="w-4 h-4 mr-1" />
                            {copied ? 'Copied!' : 'Copy'}
                        </Button>
                    </div>
                    <div className="flex items-center gap-3">
                        <Button variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={handleApply} disabled={!isValid}>
                            Apply Changes
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
