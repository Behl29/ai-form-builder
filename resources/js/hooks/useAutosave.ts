import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormSchema } from '../../types/form-schema';
import api from '../../lib/api';

interface UseAutosaveOptions {
    formId: number;
    schema: FormSchema;
    isDirty: boolean;
    debounceMs?: number;
    onSaveSuccess?: (schema: FormSchema) => void;
    onSaveError?: (error: Error) => void;
}

interface AutosaveState {
    status: 'idle' | 'saving' | 'saved' | 'error';
    lastSaved: Date | null;
    error: Error | null;
}

export function useAutosave({
    formId,
    schema,
    isDirty,
    debounceMs = 2000,
    onSaveSuccess,
    onSaveError,
}: UseAutosaveOptions) {
    const [state, setState] = useState<AutosaveState>({
        status: 'idle',
        lastSaved: null,
        error: null,
    });

    const lastSavedSchemaRef = useRef<string | null>(null);
    const saveTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isSavingRef = useRef(false);

    // Manual save function
    const save = useCallback(async () => {
        if (isSavingRef.current) return;

        const schemaJson = JSON.stringify(schema);

        // Don't save if schema hasn't changed
        if (schemaJson === lastSavedSchemaRef.current) {
            return;
        }

        isSavingRef.current = true;
        setState((s) => ({ ...s, status: 'saving', error: null }));

        try {
            await api.put(`/forms/${formId}/schema`, { schema });

            lastSavedSchemaRef.current = schemaJson;
            const now = new Date();

            setState({
                status: 'saved',
                lastSaved: now,
                error: null,
            });

            onSaveSuccess?.(schema);
        } catch (err) {
            const error = err instanceof Error ? err : new Error('Save failed');
            setState((s) => ({
                ...s,
                status: 'error',
                error,
            }));
            onSaveError?.(error);
        } finally {
            isSavingRef.current = false;
        }
    }, [formId, schema, onSaveSuccess, onSaveError]);

    // Debounced autosave effect
    useEffect(() => {
        if (!isDirty) return;

        // Clear existing timeout
        if (saveTimeoutRef.current) {
            clearTimeout(saveTimeoutRef.current);
        }

        // Set new timeout
        saveTimeoutRef.current = setTimeout(() => {
            save();
        }, debounceMs);

        return () => {
            if (saveTimeoutRef.current) {
                clearTimeout(saveTimeoutRef.current);
            }
        };
    }, [isDirty, schema, debounceMs, save]);

    // Initialize lastSavedSchema on mount
    useEffect(() => {
        lastSavedSchemaRef.current = JSON.stringify(schema);
    }, []);

    // Reset status after showing "saved" for a while
    useEffect(() => {
        if (state.status === 'saved') {
            const timeout = setTimeout(() => {
                setState((s) => ({ ...s, status: 'idle' }));
            }, 3000);
            return () => clearTimeout(timeout);
        }
    }, [state.status]);

    return {
        ...state,
        save,
        isSaving: state.status === 'saving',
    };
}

// Hook for updating schema via API
export function useUpdateSchema() {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<Error | null>(null);

    const updateSchema = useCallback(async (formId: number, schema: FormSchema) => {
        setIsLoading(true);
        setError(null);

        try {
            const { data } = await api.put(`/forms/${formId}/schema`, { schema });
            return data;
        } catch (err) {
            const error = err instanceof Error ? err : new Error('Failed to update schema');
            setError(error);
            throw error;
        } finally {
            setIsLoading(false);
        }
    }, []);

    return { updateSchema, isLoading, error };
}
