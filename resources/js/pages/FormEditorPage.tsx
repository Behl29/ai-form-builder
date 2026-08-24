import { FormBuilder } from '../components/builder';
import { ErrorState, LoadingSpinner } from '../components/ui';
import { useForm } from '../hooks/useForms';

interface FormEditorPageProps {
    formId: number;
    onBack: () => void;
}

export function FormEditorPage({ formId, onBack }: FormEditorPageProps) {
    const { data: form, isLoading, isError, error, refetch } = useForm(formId);

    if (isLoading) {
        return (
            <div className="h-screen flex items-center justify-center bg-gray-50">
                <LoadingSpinner />
            </div>
        );
    }

    if (isError || !form) {
        return (
            <div className="h-screen flex items-center justify-center bg-gray-50">
                <ErrorState
                    message={error?.message || 'Failed to load form'}
                    onRetry={() => refetch()}
                />
            </div>
        );
    }

    return (
        <FormBuilder
            form={form}
            onBack={onBack}
            onSaved={() => refetch()}
        />
    );
}
