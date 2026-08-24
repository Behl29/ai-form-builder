import { createContext, useCallback, useContext, useMemo, useReducer, type ReactNode } from 'react';
import type { FieldType, FormField, FormSchema, FormSection } from '../../types/form-schema';
import { FIELDS_WITH_OPTIONS, generateFieldId, generateSectionId, SCHEMA_VERSION } from '../../types/form-schema';

// Builder state
interface BuilderState {
    schema: FormSchema;
    selectedFieldId: string | null;
    selectedSectionId: string | null;
    previewMode: 'desktop' | 'mobile';
    isDirty: boolean;
    lastSavedSchema: FormSchema | null;
}

// Action types
type BuilderAction =
    | { type: 'SET_SCHEMA'; payload: FormSchema }
    | { type: 'SET_SELECTED_FIELD'; payload: string | null }
    | { type: 'SET_SELECTED_SECTION'; payload: string | null }
    | { type: 'SET_PREVIEW_MODE'; payload: 'desktop' | 'mobile' }
    | { type: 'ADD_FIELD'; payload: { sectionId: string; field: FormField; index?: number } }
    | { type: 'UPDATE_FIELD'; payload: { fieldId: string; updates: Partial<FormField> } }
    | { type: 'DELETE_FIELD'; payload: string }
    | { type: 'DUPLICATE_FIELD'; payload: string }
    | { type: 'MOVE_FIELD'; payload: { fieldId: string; toSectionId: string; toIndex: number } }
    | { type: 'REORDER_FIELDS'; payload: { sectionId: string; fromIndex: number; toIndex: number } }
    | { type: 'ADD_SECTION'; payload: { section: FormSection; index?: number } }
    | { type: 'UPDATE_SECTION'; payload: { sectionId: string; updates: Partial<FormSection> } }
    | { type: 'DELETE_SECTION'; payload: string }
    | { type: 'REORDER_SECTIONS'; payload: { fromIndex: number; toIndex: number } }
    | { type: 'UPDATE_METADATA'; payload: Partial<FormSchema['metadata']> }
    | { type: 'UPDATE_SETTINGS'; payload: Partial<FormSchema['settings']> }
    | { type: 'MARK_SAVED'; payload: FormSchema };

// Reducer
function builderReducer(state: BuilderState, action: BuilderAction): BuilderState {
    switch (action.type) {
        case 'SET_SCHEMA':
            return { ...state, schema: action.payload, isDirty: false, lastSavedSchema: action.payload };

        case 'SET_SELECTED_FIELD':
            return { ...state, selectedFieldId: action.payload, selectedSectionId: null };

        case 'SET_SELECTED_SECTION':
            return { ...state, selectedSectionId: action.payload, selectedFieldId: null };

        case 'SET_PREVIEW_MODE':
            return { ...state, previewMode: action.payload };

        case 'ADD_FIELD': {
            const { sectionId, field, index } = action.payload;
            const newSections = state.schema.sections.map((section) => {
                if (section.id !== sectionId) return section;
                const newFields = [...section.fields];
                if (index !== undefined) {
                    newFields.splice(index, 0, field);
                } else {
                    newFields.push(field);
                }
                return { ...section, fields: newFields };
            });
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                selectedFieldId: field.id,
                isDirty: true,
            };
        }

        case 'UPDATE_FIELD': {
            const { fieldId, updates } = action.payload;
            const newSections = state.schema.sections.map((section) => ({
                ...section,
                fields: section.fields.map((field) =>
                    field.id === fieldId ? { ...field, ...updates } : field
                ),
            }));
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                isDirty: true,
            };
        }

        case 'DELETE_FIELD': {
            const fieldId = action.payload;
            const newSections = state.schema.sections.map((section) => ({
                ...section,
                fields: section.fields.filter((field) => field.id !== fieldId),
            }));
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                selectedFieldId: state.selectedFieldId === fieldId ? null : state.selectedFieldId,
                isDirty: true,
            };
        }

        case 'DUPLICATE_FIELD': {
            const fieldId = action.payload;
            let duplicatedField: FormField | null = null;
            const newSections = state.schema.sections.map((section) => {
                const fieldIndex = section.fields.findIndex((f) => f.id === fieldId);
                if (fieldIndex === -1) return section;

                const originalField = section.fields[fieldIndex];
                duplicatedField = {
                    ...originalField,
                    id: generateFieldId(),
                    key: `${originalField.key}_copy`,
                    label: originalField.label ? `${originalField.label} (Copy)` : undefined,
                };
                const newFields = [...section.fields];
                newFields.splice(fieldIndex + 1, 0, duplicatedField);
                return { ...section, fields: newFields };
            });
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                selectedFieldId: duplicatedField?.id ?? state.selectedFieldId,
                isDirty: true,
            };
        }

        case 'MOVE_FIELD': {
            const { fieldId, toSectionId, toIndex } = action.payload;
            let movedField: FormField | null = null;

            // Remove from current section
            let newSections = state.schema.sections.map((section) => {
                const fieldIndex = section.fields.findIndex((f) => f.id === fieldId);
                if (fieldIndex === -1) return section;
                movedField = section.fields[fieldIndex];
                return {
                    ...section,
                    fields: section.fields.filter((f) => f.id !== fieldId),
                };
            });

            // Add to target section
            if (movedField) {
                newSections = newSections.map((section) => {
                    if (section.id !== toSectionId) return section;
                    const newFields = [...section.fields];
                    newFields.splice(toIndex, 0, movedField!);
                    return { ...section, fields: newFields };
                });
            }

            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                isDirty: true,
            };
        }

        case 'REORDER_FIELDS': {
            const { sectionId, fromIndex, toIndex } = action.payload;
            const newSections = state.schema.sections.map((section) => {
                if (section.id !== sectionId) return section;
                const newFields = [...section.fields];
                const [removed] = newFields.splice(fromIndex, 1);
                newFields.splice(toIndex, 0, removed);
                return { ...section, fields: newFields };
            });
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                isDirty: true,
            };
        }

        case 'ADD_SECTION': {
            const { section, index } = action.payload;
            const newSections = [...state.schema.sections];
            if (index !== undefined) {
                newSections.splice(index, 0, section);
            } else {
                newSections.push(section);
            }
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                selectedSectionId: section.id,
                isDirty: true,
            };
        }

        case 'UPDATE_SECTION': {
            const { sectionId, updates } = action.payload;
            const newSections = state.schema.sections.map((section) =>
                section.id === sectionId ? { ...section, ...updates } : section
            );
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                isDirty: true,
            };
        }

        case 'DELETE_SECTION': {
            const sectionId = action.payload;
            const newSections = state.schema.sections.filter((s) => s.id !== sectionId);
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                selectedSectionId: state.selectedSectionId === sectionId ? null : state.selectedSectionId,
                selectedFieldId: null,
                isDirty: true,
            };
        }

        case 'REORDER_SECTIONS': {
            const { fromIndex, toIndex } = action.payload;
            const newSections = [...state.schema.sections];
            const [removed] = newSections.splice(fromIndex, 1);
            newSections.splice(toIndex, 0, removed);
            return {
                ...state,
                schema: { ...state.schema, sections: newSections },
                isDirty: true,
            };
        }

        case 'UPDATE_METADATA':
            return {
                ...state,
                schema: {
                    ...state.schema,
                    metadata: { ...state.schema.metadata, ...action.payload },
                },
                isDirty: true,
            };

        case 'UPDATE_SETTINGS':
            return {
                ...state,
                schema: {
                    ...state.schema,
                    settings: { ...state.schema.settings, ...action.payload },
                },
                isDirty: true,
            };

        case 'MARK_SAVED':
            return { ...state, isDirty: false, lastSavedSchema: action.payload };

        default:
            return state;
    }
}

// Context
interface BuilderContextValue {
    state: BuilderState;
    // Schema operations
    setSchema: (schema: FormSchema) => void;
    markSaved: (schema: FormSchema) => void;
    // Selection
    selectField: (fieldId: string | null) => void;
    selectSection: (sectionId: string | null) => void;
    setPreviewMode: (mode: 'desktop' | 'mobile') => void;
    // Field operations
    addField: (sectionId: string, type: FieldType, index?: number) => void;
    updateField: (fieldId: string, updates: Partial<FormField>) => void;
    deleteField: (fieldId: string) => void;
    duplicateField: (fieldId: string) => void;
    moveField: (fieldId: string, toSectionId: string, toIndex: number) => void;
    reorderFields: (sectionId: string, fromIndex: number, toIndex: number) => void;
    // Section operations
    addSection: (index?: number) => void;
    updateSection: (sectionId: string, updates: Partial<FormSection>) => void;
    deleteSection: (sectionId: string) => void;
    reorderSections: (fromIndex: number, toIndex: number) => void;
    // Metadata/settings
    updateMetadata: (updates: Partial<FormSchema['metadata']>) => void;
    updateSettings: (updates: Partial<FormSchema['settings']>) => void;
    // Helpers
    getSelectedField: () => FormField | null;
    getSelectedSection: () => FormSection | null;
    getAllFieldKeys: () => string[];
    isKeyUnique: (key: string, excludeFieldId?: string) => boolean;
}

const BuilderContext = createContext<BuilderContextValue | null>(null);

// Initial state
function createInitialState(schema?: FormSchema): BuilderState {
    const defaultSchema: FormSchema = schema ?? {
        schemaVersion: SCHEMA_VERSION,
        metadata: { title: 'Untitled Form' },
        settings: { submitButtonText: 'Submit' },
        sections: [{ id: generateSectionId(), title: 'Section 1', fields: [] }],
    };

    return {
        schema: defaultSchema,
        selectedFieldId: null,
        selectedSectionId: null,
        previewMode: 'desktop',
        isDirty: false,
        lastSavedSchema: defaultSchema,
    };
}

// Provider
interface BuilderProviderProps {
    children: ReactNode;
    initialSchema?: FormSchema;
}

export function BuilderProvider({ children, initialSchema }: BuilderProviderProps) {
    const [state, dispatch] = useReducer(builderReducer, initialSchema, createInitialState);

    const setSchema = useCallback((schema: FormSchema) => {
        dispatch({ type: 'SET_SCHEMA', payload: schema });
    }, []);

    const markSaved = useCallback((schema: FormSchema) => {
        dispatch({ type: 'MARK_SAVED', payload: schema });
    }, []);

    const selectField = useCallback((fieldId: string | null) => {
        dispatch({ type: 'SET_SELECTED_FIELD', payload: fieldId });
    }, []);

    const selectSection = useCallback((sectionId: string | null) => {
        dispatch({ type: 'SET_SELECTED_SECTION', payload: sectionId });
    }, []);

    const setPreviewMode = useCallback((mode: 'desktop' | 'mobile') => {
        dispatch({ type: 'SET_PREVIEW_MODE', payload: mode });
    }, []);

    const addField = useCallback((sectionId: string, type: FieldType, index?: number) => {
        const baseField = {
            id: generateFieldId(),
            key: `field_${Date.now()}`,
            type,
            label: getDefaultLabel(type),
        };

        let field: FormField;
        if (FIELDS_WITH_OPTIONS.includes(type)) {
            field = { ...baseField, options: [{ value: 'option1', label: 'Option 1' }] } as FormField;
        } else {
            field = baseField as FormField;
        }

        dispatch({ type: 'ADD_FIELD', payload: { sectionId, field, index } });
    }, []);

    const updateField = useCallback((fieldId: string, updates: Partial<FormField>) => {
        dispatch({ type: 'UPDATE_FIELD', payload: { fieldId, updates } });
    }, []);

    const deleteField = useCallback((fieldId: string) => {
        dispatch({ type: 'DELETE_FIELD', payload: fieldId });
    }, []);

    const duplicateField = useCallback((fieldId: string) => {
        dispatch({ type: 'DUPLICATE_FIELD', payload: fieldId });
    }, []);

    const moveField = useCallback((fieldId: string, toSectionId: string, toIndex: number) => {
        dispatch({ type: 'MOVE_FIELD', payload: { fieldId, toSectionId, toIndex } });
    }, []);

    const reorderFields = useCallback((sectionId: string, fromIndex: number, toIndex: number) => {
        dispatch({ type: 'REORDER_FIELDS', payload: { sectionId, fromIndex, toIndex } });
    }, []);

    const addSection = useCallback((index?: number) => {
        const section: FormSection = {
            id: generateSectionId(),
            title: `Section ${state.schema.sections.length + 1}`,
            fields: [],
        };
        dispatch({ type: 'ADD_SECTION', payload: { section, index } });
    }, [state.schema.sections.length]);

    const updateSection = useCallback((sectionId: string, updates: Partial<FormSection>) => {
        dispatch({ type: 'UPDATE_SECTION', payload: { sectionId, updates } });
    }, []);

    const deleteSection = useCallback((sectionId: string) => {
        dispatch({ type: 'DELETE_SECTION', payload: sectionId });
    }, []);

    const reorderSections = useCallback((fromIndex: number, toIndex: number) => {
        dispatch({ type: 'REORDER_SECTIONS', payload: { fromIndex, toIndex } });
    }, []);

    const updateMetadata = useCallback((updates: Partial<FormSchema['metadata']>) => {
        dispatch({ type: 'UPDATE_METADATA', payload: updates });
    }, []);

    const updateSettings = useCallback((updates: Partial<FormSchema['settings']>) => {
        dispatch({ type: 'UPDATE_SETTINGS', payload: updates });
    }, []);

    const getSelectedField = useCallback((): FormField | null => {
        if (!state.selectedFieldId) return null;
        for (const section of state.schema.sections) {
            const field = section.fields.find((f) => f.id === state.selectedFieldId);
            if (field) return field;
        }
        return null;
    }, [state.selectedFieldId, state.schema.sections]);

    const getSelectedSection = useCallback((): FormSection | null => {
        if (!state.selectedSectionId) return null;
        return state.schema.sections.find((s) => s.id === state.selectedSectionId) ?? null;
    }, [state.selectedSectionId, state.schema.sections]);

    const getAllFieldKeys = useCallback((): string[] => {
        return state.schema.sections.flatMap((s) => s.fields.map((f) => f.key));
    }, [state.schema.sections]);

    const isKeyUnique = useCallback((key: string, excludeFieldId?: string): boolean => {
        for (const section of state.schema.sections) {
            for (const field of section.fields) {
                if (field.key === key && field.id !== excludeFieldId) {
                    return false;
                }
            }
        }
        return true;
    }, [state.schema.sections]);

    const value = useMemo<BuilderContextValue>(() => ({
        state,
        setSchema,
        markSaved,
        selectField,
        selectSection,
        setPreviewMode,
        addField,
        updateField,
        deleteField,
        duplicateField,
        moveField,
        reorderFields,
        addSection,
        updateSection,
        deleteSection,
        reorderSections,
        updateMetadata,
        updateSettings,
        getSelectedField,
        getSelectedSection,
        getAllFieldKeys,
        isKeyUnique,
    }), [
        state, setSchema, markSaved, selectField, selectSection, setPreviewMode,
        addField, updateField, deleteField, duplicateField, moveField, reorderFields,
        addSection, updateSection, deleteSection, reorderSections,
        updateMetadata, updateSettings, getSelectedField, getSelectedSection,
        getAllFieldKeys, isKeyUnique,
    ]);

    return <BuilderContext.Provider value={value}>{children}</BuilderContext.Provider>;
}

// Hook
export function useBuilder() {
    const context = useContext(BuilderContext);
    if (!context) {
        throw new Error('useBuilder must be used within a BuilderProvider');
    }
    return context;
}

// Helper
function getDefaultLabel(type: FieldType): string {
    const labels: Record<FieldType, string> = {
        text: 'Text Field',
        textarea: 'Text Area',
        number: 'Number',
        email: 'Email',
        phone: 'Phone',
        date: 'Date',
        select: 'Dropdown',
        radio: 'Radio Group',
        checkbox_group: 'Checkbox Group',
        checkbox: 'Checkbox',
        file: 'File Upload',
        heading: 'Section Heading',
        rating: 'Rating',
        url: 'URL',
    };
    return labels[type];
}
